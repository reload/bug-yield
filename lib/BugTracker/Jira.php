<?php

class JiraBugTracker implements BugTracker {

  private $api    = NULL;
  private $token  = NULL;
  private $name   = "Jira";
  private $urlTicketPattern = '/browse/%1$s?focusedWorklogId=%2$d&page=com.atlassian.jira.plugin.system.issuetabpanels%%3Aworklog-tabpanel#worklog-%2$d';

  public function getName() {
    return $this->name;
  }

  public function getUrlTicketPattern() {
    return $this->urlTicketPattern;
  }

  public function getApi($url, $username, $password) {
    $this->api= new SoapClient($url . '/rpc/soap/jirasoapservice-v2?wsdl');
    $this->token = $this->api->login($username, $password);
  }

  public function getTitle($ticketId) {
    $ticketId = ltrim($ticketId, '#');

    // the Jira throws an exception if the issue does not exists or are unreachable. We don't want that, hence the try/catch
    try {
      $response = $this->api->getIssue($this->token, $ticketId);
    } catch (Exception $e) {
      //TODO: Valuable information will be returned from Jira here, we should log it somewhere. E.g.:
      // com.atlassian.jira.rpc.exception.RemotePermissionException: This issue does not exist or you don't have permission to view it.
      return FALSE;
    }

    if (is_object($response) && isset($response->summary)) {
      return $response->summary;
    }

    return FALSE;
  }

  public function extractIds($string) {
    $ids = array();
    if (preg_match_all('/(#[A-Za-z]+-\d+)/', $string, $matches)) {
      $ids = array_map('strtoupper', $matches[1]);
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
    // weed out newlines in notes
    $timelog->notes = preg_replace('/[\n\r]+/m' , ' ', $timelog->notes);

    $worklog = new stdClass;

    // Set the Jira worklog ID on the worklog object if this Harvest
    // entry is already tracked in Jira.
    $entries = $this->getTimelogEntries($ticketId);
    foreach ($entries as $entry) {
      if (isset($entry->harvestId) && ($entry->harvestId == $timelog->harvestId)) {
        // if we are about to update an existing Harvest entry set the
        // Jira id on the worklog entry
        $worklog->id = $entry->remoteId;
      }
      else {
        // if this is an existing Harvest entry - but it doesn't match
        // this Jira entry OR if this is not a BugYield entry continue
        // to the next entry
        continue;
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
    
    // Load issue so we can check its status and decided whether it
    // is in an editable state.
    $issue = $this->api->getIssue($this->token, $ticketId);
    
    // Reopen issue if status is "Closed" (6) which is non-editable.
    if ($issue->status == 6) {
      $fields[] = array();
      // Action ID 3 is "Reopen issue".
      $this->api->progressWorkflowAction($this->token, $issue->key, 3, $fields);
    }

    // If this is an existing entry update it - otherwise add it.
    if (isset($worklog->id)) {
      // Update the Registered time. Jira can't log worklog entries
      // with hours == 0 so delete the worklog entry in that case.
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

    // If issue status was "Closed" (6) we need to close the issue
    // again (and set status and resolution back to original
    // values).
    if ($issue->status == 6) {
      $fields[] = array('id' => 'resolution', 'values' => array($issue->resolution));
      $fields[] = array('id' => 'status', 'values' => array($issue->status));
      $this->api->progressWorkflowAction($this->token, $issue->key, 2, $fields);
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

  /**
   * Preparing this for JIRA, e.g. removing #hashmark, transforming "#scl-123" to "SCL-123"
   */
  public function sanitizeTicketId($ticketId) {
    $ticketId = trim(strtoupper(str_replace("#","",$ticketId)));
    return $ticketId;
  }
}
