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

    /**
     * Get URL to ticket
     *
     * @param string $ticketId
     *   ID of ticket, eg "4564" or "SCL-34".
     * @param integer $remoteId
     *   EventID of the exact worklog item/comment, eg "12344".
     * @return string
     *   The URL.
     */
    public function getTicketURL($ticketId, $remoteId);
}
