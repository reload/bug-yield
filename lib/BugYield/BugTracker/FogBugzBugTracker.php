<?php

namespace BugYield\BugTracker;

class FogBugzBugTracker implements \BugYield\BugTracker\BugTracker {

  private $api = NULL;
  private $name = "FogBugz";
  private $urlTicketPattern = '/default.asp?%1$s#BugEvent.%2$d';

  public function getName() {
    return $this->name;
  }

  public function getUrlTicketPattern() {
    return $this->urlTicketPattern;
  }

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
    return self::getHarvestEntriesFromFBTicket($response);
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
    // Sanitize input.
    $ticketId = ltrim($ticketId, '#');
    $timelog->notes = preg_replace('/[\r\n]+/', ' ', $timelog->notes);

    $update = FALSE;
    $elapsed_hours = 0;
    $entries = $this->getTimelogEntries($ticketId);

    // Calculate all registered elapsed hours.
    foreach ($entries as $entry) {
      $elapsed_hours += $entry->hours;
    }

    // If Entry is already registered - do an update if anything
    // changed.
    if (array_key_exists($timelog->harvestId, $entries)) {
      // Bail out if nothing changed
      if ($entries[$timelog->harvestId]->harvestId == $timelog->harvestId &&
          $entries[$timelog->harvestId]->user      == $timelog->user      &&
          $entries[$timelog->harvestId]->hours     == $timelog->hours     &&
          $entries[$timelog->harvestId]->spentAt   == $timelog->spentAt   &&
          $entries[$timelog->harvestId]->project   == $timelog->project   &&
          $entries[$timelog->harvestId]->taskName  == $timelog->taskName  &&
          $entries[$timelog->harvestId]->notes     == $timelog->notes) {
        return false;
      }

      // Adjust elapsed hours and mark as an update entry.
      $elapsed_hours -= $entries[$timelog->harvestId]->hours;
      $update = TRUE;
    }

    // Advance elapsed hours.
    $elapsed_hours += $timelog->hours;

    //Update case with new or updated entry and time spent
    $params = array();
    $params['token']  = $this->api->getToken()->_data['token'];
    $params['cmd']    = 'edit';
    $params['ixBug']  = $ticketId;
    $params['sEvent'] = vsprintf('Entry #%d [%s/%s]: "%s" by %s @ %s in "%s"%s',
    array(
          $timelog->harvestId,
          $timelog->hours,
          $timelog->taskName,
          $timelog->notes,
          $timelog->user,
          $timelog->spentAt,
          $timelog->project,
          $update ? ' (updated)' : '',
          ));

    // We need to use , (comma) instead of . (period) as separator
    // when reporting hours with decimals. Silly FogBugz.
    $params['hrsElapsedExtra'] = number_format($elapsed_hours, 2, ',', '');

    // Add the (updated) data to the FogBugz entry.
    $request = new \FogBugz_Request($this->api);
    $request->setParams($params);
    $response = $request->go();

    return $update;
  }

  /**
   * Fetch the Harvest data from the FogBugz updates. 
   *
   * @param FogBugz_Response_Cases $response
   * @return Array Matches from the regex preg_match
   */
  private function getHarvestEntriesFromFBTicket(\FogBugz_Response_Cases $response, $include_overwritten_entries = FALSE) {
    
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
            $timelog = new \stdClass;
            $timelog->harvestId = $matches[1];
            $timelog->hours     = $matches[2];
            $timelog->taskName  = self::convertAndCleanData($matches[3]);
            $timelog->notes     = self::convertAndCleanData($matches[4]);
            $timelog->user      = self::convertAndCleanData($matches[5]);
            $timelog->spentAt   = $matches[6];
            $timelog->project   = self::convertAndCleanData($matches[7]);
            $timelog->updated   = isset($matches[8]) ? TRUE : FALSE;
            $timelog->remoteId  = $event->_data['ixBugEvent'];
            
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

  /**
   * Return the TicketID in its purest form, e.g. removing #hashmarks
   */
  public function sanitizeTicketId($ticketId) {
    $ticketId = intval(str_replace("#","",$ticketId));
    return $ticketId;
  }

  /**
   * Fogbugz returns data in a wierd format. Convert and clean in up for easier comparison of strings
   */
  private function convertAndCleanData($data) {
    $data = trim(trim(strip_tags(html_entity_decode(str_replace("&nbsp;"," ",$data), ENT_COMPAT, "UTF-8"))), '"');
    return $data;
  }
}
