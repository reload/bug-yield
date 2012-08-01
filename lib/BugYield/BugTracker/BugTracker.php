<?php

namespace BugYield\BugTracker;

interface BugTracker {

  // Name of the tracker for presentation purposes.
  /**
   * Instantiate a bugtracker.
   * @param array $config Configuration options for the bugtracker.
   */
  public function __construct($config);

  public function getName();
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

  public function sanitizeTicketId($ticketId);

  public function getUrlTicketPattern();
}
