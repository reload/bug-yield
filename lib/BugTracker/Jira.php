<?php

class JiraBugTracker implements BugTracker {

  private $api = NULL;
  private $token = NULL;

  public function getApi($url, $username, $password) {
    $this->api= new SoapClient($url . '/rpc/soap/jirasoapservice-v2?wsdl');
    $this->token = $this->api->login($username, $password);
  }

  public function getTitle($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $response = $this->api->getIssue($this->token, $ticketId);

    if (is_object($response) && isset($response->summary)) {
      return $response->summary;
    }

    return FALSE;
  }

  public function extractIds($string) {
    $ids = array();
    if (preg_match_all('/(#[A-Z]+-\d+)/', $string, $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
  }

  public function getTimelogEntries($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $entries = $this->api->getWorklogs($this->token, $ticketId);
    $timelogs = array();
    foreach ($entries as $entry) {
      $timelog = $this->parseComment($entry->comment);
      $timelog->hours = (string) round($entry->timeSpentInSeconds / 3600, 2);
      $timelog->spentAt = date('Y-m-d', strtotime($entry->startDate));
      $timelog->remoteId = $entry->id;
      $timelogs[] = $timelog;
    }
    return $timelogs;
  }

  public function saveTimelogEntry($ticketId, $timelog) {
    $ticketId = ltrim($ticketId, '#');
    $worklog = new stdClass;

    // Set the Jira worklog ID on the worklog object if this Harvest
    // entry is already tracked in Jira.
    $entries = $this->getTimelogEntries($ticketId);
    foreach ($entries as $entry) {
      if ($entry->harvestId == $timelog->harvestId) {
        $worklog->id = $entry->remoteId;
      }

      // Bail out if we don't need to actually update anything.
      if ($entry->harvestId == $timelog->harvestId &&
          $entry->user      == $timelog->user      &&
          $entry->hours     == $timelog->hours     &&
          $entry->spentAt   == $timelog->spentAt   &&
          $entry->project   == $timelog->project   &&
          $entry->taskName  == $timelog->taskName  &&
          $entry->notes     == $timelog->notes) {
        return;
      }
    }

    $worklog->comment = $this->formatComment($timelog);
    $worklog->startDate = date('c', strtotime($timelog->spentAt));
    $worklog->timeSpent = $timelog->hours . 'h';

    // Caveat in the Jira API - the parameter below must be set but the
    // value is ignored so we just set it to NULL.
    $worklog->timeSpentInSeconds = NULL;
    
    // If this is an existing entry update it - otherwise add it.
    if (isset($worklog->id)) {
      // Jira can't log entries with hours == 0
      if ($timelog->hours == 0) {
        $this->api->deleteWorklogAndAutoAdjustRemainingEstimate($this->token, $worklog->id);
      }
      else {
        $this->api->updateWorklogAndAutoAdjustRemainingEstimate($this->token, $worklog);
      }
    }
    else {
      // Jira can't log entries with hours == 0
      if ($timelog->hours != 0) {
        $this->api->addWorklogAndAutoAdjustRemainingEstimate($this->token, $ticketId, $worklog);
      }
      else {
	// intentionally left blank
      }
    }
  }

  /**
   * A comment entry will be formatted like this:
   *
   * Entry #71791646 Kode: "Fikser #4029[tester harvest med anton]" by Rasmus Luckow-Nielsen in "BugYield test"
   */
  private function parseComment($comment) {
    $timelog = new stdClass;
    $num_matches = preg_match('/^Entry\s#(\d+)\s\[([^]]*)\]:\s"(.*)"\sby\s(.*)\sin\s"(.*)"/m', $comment, $matches);
    if ($num_matches > 0) {
      $timelog->harvestId = $matches[1];
      $timelog->taskName  = $matches[2];
      $timelog->notes     = $matches[3];
      $timelog->user      = $matches[4];
      $timelog->project   = $matches[5];
    }
    return $timelog;
  }

  private function formatComment($timelog) {
    return vsprintf('Entry #%d [%s]: "%s" by %s in "%s"',
                    array(
                          $timelog->harvestId,
                          $timelog->taskName,
                          preg_replace('/[\n\r]+/m', ' ', $timelog->notes),
                          $timelog->user,
                          $timelog->project,
                          ));
  }
}
