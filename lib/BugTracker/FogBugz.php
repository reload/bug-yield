<?php

class FogBugzBugTracker implements BugTracker {

  private $api = NULL;

  public function getApi($url, $username, $password) {
    $this->api = new \FogBugz($url, $username, $password);
    $this->api->logon();
  }

  public function getTitle($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $response = $this->api->search($ticketId, 'sTitle', 1);

    if ($case = array_shift($response->_data)) {
      return $case->_data['sTitle'];
    }

    return FALSE;
  }

  public function extractIds($string) {
    $ids = array();
    if (preg_match_all('/(#\d+)/', $string, $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
  }

  public function getTimelogEntries($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $response = $this->api->search($ticketId, 'sTitle,hrsElapsedExtra,events', 1);
    // @todo extract harvest related worklog entries
    return $checkFogBugzEntries = self::getHarvestEntriesFromFBTicket($response);
  }

  /**
   * @param object $timelogEntry
   *   harvestId
   *   user
   *   hours
   *   spentAt
   *   project
   *   taskName
   *   notes
   *   remoteId - for internal use
   */
  public function saveTimelogEntry($ticketId, $timelog) {
    $ticketId = ltrim($ticketId, '#');

    $entries = $this->getTimelogEntries($ticketId);
    foreach ($entries as $entry) {
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
      $this->api->updateWorklogAndAutoAdjustRemainingEstimate($this->token, $ticketId, $worklog);      
    }
    else {
      $this->api->addWorklogAndAutoAdjustRemainingEstimate($this->token, $ticketId, $worklog);
    }
  }


  /**
   * Fetch the Harvest data from the FogBugz updates. 
   *
   * @param FogBugz_Response_Cases $response
   * @return Array Matches from the regex preg_match
   */
  protected function getHarvestEntriesFromFBTicket(\FogBugz_Response_Cases $response, $include_overwritten_entries = FALSE) {
    
    $harvestEntries = array();

    if(!isset($response->_data)) {
      error_log("no data from this response");
      return $harvestEntries;
    }
    
    foreach($response->_data as $case) {

      $fbId = $case->_data['ixBug'];
      if (isset($case->_data['events'])) {
        //Reverse the order of events to get the most recent first.
        //These will contain the latest updates in regard to time and task.
        $events = $case->_data['events']->_data;
        foreach ($events as $event) {
          $text = (isset($event->_data['sHtml'])) ? $event->_data['sHtml'] : $event->_data['s'];
          if (is_string($text) && preg_match('/^Entry\s#([0-9]+)\s\[(.*?)\/(.*?)\]:\s(.*?)by\s(.*?)@\s([0-9-]+)\sin\s(.*?)(\s\(updated\))?$/', $text, $matches)) {
            $timelog = new stdClass;
            $timelog->harvestId = $matches[1];
            $timelog->hours     = $matches[2];
            $timelog->taskName  = $matches[3];
            $timelog->notes     = trim(trim(strip_tags(html_entity_decode($matches[4], ENT_COMPAT, "UTF-8"))), '"');
            $timelog->user      = $matches[5];
            $timelog->spentAt   = $matches[6];
            $timelog->project   = trim(trim(strip_tags(html_entity_decode($matches[7], ENT_COMPAT, "UTF-8"))), '"');
            $timelog->updated   = isset($matches[8]) ? TRUE : FALSE;
            
            if ($include_overwritten_entries) {
              $harvestEntries[] = $timelog;
            }
            else {
              $harvestEntries[$matches[1]] = $timelog;
            }
          }
        }
      }
      
    }
    
    return $harvestEntries;
  }
}
