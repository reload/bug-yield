<?php

namespace BugYield\BugTracker;

use Symfony\Component\Yaml\Exception;

interface BugTracker
{
    /**
     * Name of the bugtracker for presentation purposes.
     */
    public function getName(): string;

    /**
     * Url of the bugtracker.
     */
    public function getURL(): string;

    /**
     * Get the title of the ticket.
     *
     * May return false or throw an exception.
     */
    public function getTitle(string $ticketId);

    /**
     * Get ticket ids found in the string.
     */
    public function extractIds(string $string): array;

    /**
     * Get time log entries.
     */
    public function getTimelogEntries(string $ticketId): array;

    /**
     * Save a timelog entry.
     *
     * @param object $timelogEntry
     *   harvestId
     *   user
     *   hours
     *   spentAt
     *   project
     *   taskName
     *   notes
     *   remoteId - for internal use
     *
     * @return bool
     *   Whether the timelog was saved.
     */
    public function saveTimelogEntry(string $ticketId, $timelogEntry): bool;

    /**
     * Delete a timelog entry
     *
     * @todo Obviously misnamed.
     */
    public function deleteWorkLogEntry(string $worklogId, string $issueId): bool;

    /**
     * Get URL to ticket
     *
     * @param string $ticketId
     *   ID of ticket, eg "4564" or "SCL-34".
     * @param string $remoteId
     *   EventID of the exact worklog item/comment, eg "12344".
     * @return string
     *   The URL.
     */
    public function getTicketURL(string $ticketId, string $remoteId): string;
}
