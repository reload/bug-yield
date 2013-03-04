<?php

namespace BugYield\BugTracker;

interface BugTracker {

  // Name of the tracker for presentation purposes.
  public function getName();
  public function getApi($url, $username, $password);
  public function getTitle($ticketId);
  public function extractIds($string);
  public function getTimelogEntries($ticketId);

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
  public function saveTimelogEntry($ticketId, $timelogEntry);

  public function deleteWorkLogEntry($worklogId);

  public function sanitizeTicketId($ticketId);

  public function getUrlTicketPattern();

  public function setOptions($bugtrackerConfig);
}
