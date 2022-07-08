<?php

namespace BugYield\BugTracker;

use JiraApi\Clients\IssueClient as JiraApi;

class Jira extends BugTrackerBase
{

    private $api;
    private $token;
    public $currentUsername;
    private $name   = "Jira";

    /**
     * Default URL to issues.
     */
    private $urlTicketPattern = '/browse/%1$s?focusedWorklogId=%2$d&page=com.atlassian.jira.plugin.' .
        'system.issuetabpanels%%3Aworklog-tabpanel#worklog-%2$d';
    private $bugtrackerConfig;

    public function __construct($bugtrackerConfig)
    {
        $this->bugtrackerConfig = $bugtrackerConfig;
        $this->getApi($bugtrackerConfig['url'], $bugtrackerConfig['username'], $bugtrackerConfig['password']);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getURL(): string
    {
        return $this->bugtrackerConfig['url'];
    }

    /**
     * {@inheritdoc}
     */
    public function getTicketURL(string $ticketId, string $remoteId = null): string
    {

        $urlTicketPattern = $this->bugtrackerConfig('url_ticket_pattern');
        if (is_null($urlTicketPattern) || empty($urlTicketPattern)) {
            // fetch the default fallback url
            $urlTicketPattern = $this->urlTicketPattern;
        }

        return sprintf($this->getURL() . $urlTicketPattern, $ticketId, $remoteId);
    }

    /**
     * Instantiate API handler.
     *
     * @todo Obviously misnamed.
     */
    protected function getApi(string $url, string $username, string $password): void
    {
        $this->currentUsername = $username;
        $this->api = new JiraApi(rtrim($url, '/') . '/rest/api/2/', $username, $password);
    }

    /**
     * Get issue based on ticketId.
     */
    public function getIssue(string $ticketId): array
    {
        return $this->api->get($ticketId)->json();
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(string $ticketId)
    {
        // Jira throws an exception if the issue does not exists or are
        // unreachable.
        $response = $this->getIssue($ticketId);

        if (is_array($response)) {
            return $response['fields']['summary'];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function extractIds($string): array
    {
        $ids = array();
        // The (?<![A-Z0-9_-]) is to ensure that we don't match things like
        // "1-on-1" or "1stuff-2".
        if (preg_match_all('/(?<![A-Z0-9_-])([A-Z][A-Z0-9]+-\d+)/i', $string, $matches)) {
            $ids = array_map('strtoupper', $matches[1]);
        }
        return array_unique($ids);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimelogEntries(string $ticketId): array
    {
        $response = $this->api->getFullWorklog($ticketId)->json();

        $timelogs = array();
        foreach ($response['worklogs'] as $entry) {
            $timelog = $this->parseComment($entry['comment']);
            // Harvest uses two decimals, so we do the same. Using floor
            // rather than round seem to hit the munged value we set in
            // saveTimelogEntry() better.
            $timelog->hours = (string) floor($entry['timeSpentSeconds'] / 36) / 100;
            $timelog->started = $entry['started'];
            $timelog->spentAt = date('Y-m-d', strtotime($entry['started']));
            $timelog->remoteId = $entry['id'];
            $timelogs[] = $timelog;
        }
        return $timelogs;
    }

    /**
     * {@inheritdoc}
     */
    public function saveTimelogEntry(string $ticketId, $timelog): bool
    {
        // Weed out newlines in notes.
        $timelog->notes = preg_replace('/[\n\r]+/m', ' ', $timelog->notes);

        $worklog = new \stdClass();

        // Set the Jira worklog ID on the worklog object if this Harvest
        // entry is already tracked in Jira.
        $entries = $this->getTimelogEntries($ticketId);
        foreach ($entries as $entry) {
            if (isset($entry->harvestId) && ($entry->harvestId == $timelog->harvestId)) {
                // if we are about to update an existing Harvest entry set the
                // Jira id on the worklog entry
                $worklog->id = $entry->remoteId;
            } else {
                // if this is an existing Harvest entry - but it doesn't match
                // this Jira entry OR if this is not a BugYield entry continue
                // to the next entry
                continue;
            }

            // Bail out if we don't need to actually update anything.
            if (
                $entry->harvestId == $timelog->harvestId &&
                $entry->user      == $timelog->user      &&
                $entry->hours     == $timelog->hours     &&
                $entry->spentAt   == $timelog->spentAt   &&
                $entry->project   == $timelog->project   &&
                $entry->taskName  == $timelog->taskName  &&
                $entry->notes     == $timelog->notes
            ) {
                return false;
            }
        }

        $worklog->comment = $this->formatComment($timelog);
        // Try to account for Harvest and Jira being lax about time-frames. An
        // example: 50 minutes is 0.833333333333 (with as many decimals as
        // precision allows) hour. But Harvest rounds hours to two decimals
        // when we fetch entries. That's 0.83, which is 49 minutes and 48
        // seconds. That gets converted to 2988 seconds when we post it to
        // Jira. But Jira only works in minutes, so that gets rounded to 2940
        // seconds when we fetch the worklog the next time, so we end up with
        // 0.816666666667 (again, infinite decimals), which we round to 0.82
        // hours (which is 49 minutes 12 seconds) that doesn't match the 0.83
        // from Harvest.
        //
        // To deal with this, we round the time to the nearest minute before
        // sending it to Jira, then Jira at least wont loose time, and when we
        // convert that into hours and limit to two decimals, we'll hopefully
        // hit the same number as we got from Harvest.
        $worklog->timeSpentSeconds = round($timelog->hours * 60) * 60;
        // We don't know when the entry was actually started, so we'll use the
        // spentAt date and use 23:00.
        $worklog->started = $timelog->spentAt . "T23:00:00.000+0200";


        // Check if individual logging is enabled.
        if (!empty($this->bugtrackerConfig['worklog_individual_logins'])) {
            if (!empty($this->bugtrackerConfig['users'][$timelog->userEmail])) {
                // Get user credentials configuration.
                $username = $this->bugtrackerConfig['users'][$timelog->userEmail]['username'];
                $password = $this->bugtrackerConfig['users'][$timelog->userEmail]['password'];

                // Initialize API for the specific user.
                if ($this->currentUsername != $username) {
                    // @todo: shouldn't just print here, DI'ing some sort of
                    // logger which would then be wired up to stdout would
                    // probably be the right way.
                    print 'Jira: Switching user to ' . $timelog->userEmail . "\n";
                    $url = $this->bugtrackerConfig['url'];
                    $this->getApi($url, $username, $password);
                }
            } else {
                print "Jira: Error, user credentials not found for $timelog->userEmail \n";
                if (!empty($this->bugtrackerConfig['worklog_allow_admin'])) {
                    print "Jira: Switching to admin user for fallback logging enabled... \n";
                    // Switch to admin user if already logged in as specific user.
                    if ($this->currentUsername != $this->bugtrackerConfig['username']) {
                        $this->getApi(
                            $this->bugtrackerConfig['url'],
                            $this->bugtrackerConfig['username'],
                            $this->bugtrackerConfig['password']
                        );
                    }
                } else {
                    throw new \Exception('JIRA credentials required but not found for the user ' . $timelog->userEmail);
                }
            }
        }

        // If this is an existing entry update it - otherwise add it.
        if (isset($worklog->id)) {
            // Update the Registered time. Jira can't log worklog entries
            // with hours == 0 so delete the worklog entry in that case.
            if ($timelog->hours == 0) {
                $this->deleteWorkLogEntry($worklog->id, $ticketId);
            } else {
                $this->api->updateWorklog($ticketId, $worklog->id, (array) $worklog);
            }
        } else {
            // Jira can't log entries with hours == 0
            if ($timelog->hours != 0) {
                $this->api->createWorklog($ticketId, (array) $worklog);
            } else {
                // intentionally left blank
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Delete the worklog, but retain the remaining estimate
     *
     * (when auto-adjusting the removed time will be added to the remaining work)
     */
    public function deleteWorkLogEntry($worklogId, $issueId): bool
    {
        $this->api->deleteWorklog($issueId, $worklogId);
        return true;
    }

    /**
     * Parse a worklog comment into a timelog.
     *
     * A comment entry will be formatted like this:
     *
     * Entry #71791646 Kode: "Fikser PROJ-12[tester harvest med anton]" by Rasmus Luckow-Nielsen in "BugYield test"
     */
    private function parseComment(string $comment)
    {
        $timelog = new \stdClass();
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

    /**
     * Format a timelog into a comment.
     *
     * @param object $timelog
     */
    private function formatComment($timelog): string
    {
        return vsprintf(
            'Entry #%d [%s]: "%s" by %s in "%s"',
            array(
                $timelog->harvestId,
                $timelog->taskName,
                preg_replace('/[\n\r]+/m', ' ', $timelog->notes),
                $timelog->user,
                $timelog->project,
            )
        );
    }
}
