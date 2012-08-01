<?php

namespace BugYield\BugTracker;

/**
 * Interface for bug tracking systems i.e. systems which contain
 * issues which support time tracking and should recieve time
 * log entries.
 */
interface BugTracker {

  /**
   * Instantiate a bugtracker.
   * @param array $config Configuration options for the bugtracker.
   */
  public function __construct($config);

  /**
   * Return name of the tracker for presentation purposes.
   * @return string Name of bug tracker
   */ 
  public function getName();

  /**
   * Return title of a ticket from the bugtracker.
   * @param  string $ticketId The ticket id
   * @return string           The title of the title
   */
  public function getTitle($ticketId);

  /**
   * Extract ticket ids matching the bugtrackers ticket pattern.
   * @param  string $string A string
   * @return array          An array of ticket ids contained within the string
   */
  public function extractIds($string);

  /**
   * Retrieve time logger entries registrered with the bugtracker ticket.
   * @param  string $ticketId The ticket id
   * @return array            An array of time log entry objects.
   */
  public function getTimelogEntries($ticketId);

  /**
   * Save a time log entry to a ticket in the bug tracker.
   * @param  string $ticketId     The ticket id
   * @param  object $timelogEntry
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
