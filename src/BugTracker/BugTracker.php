<?php

namespace BugYield\BugTracker;

use Symfony\Component\Yaml\Exception;

interface BugTracker
{

  // Name of the tracker for presentation purposes.
    public function getName();
    public function getURL();
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

    public function deleteWorkLogEntry($worklogId, $issue);

    public function sanitizeTicketId($ticketId);

    public function getUrlTicketPattern();
}
