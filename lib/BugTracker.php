<?php

interface BugTracker {
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
}

require_once __DIR__ . '/BugTracker/FogBugz.php';
require_once __DIR__ . '/BugTracker/Jira.php';
