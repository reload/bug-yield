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
    private $urlTicketPattern = '/browse/%1$s?focusedWorklogId=%2$d&page=com.atlassian.jira.plugin.system.issuetabpanels%%3Aworklog-tabpanel#worklog-%2$d';
    private $bugtrackerConfig;

    public function __construct($bugtrackerConfig)
    {
        $this->bugtrackerConfig = $bugtrackerConfig;
        $this->getApi($bugtrackerConfig['url'], $bugtrackerConfig['username'], $bugtrackerConfig['password']);
    }

    public function getName()
    {
        return $this->name;
    }

    public function getURL()
    {
        return $this->bugtrackerConfig['url'];
    }

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
    public function getTicketURL($ticketId, $remoteId = null)
    {

        $urlTicketPattern = $this->bugtrackerConfig('url_ticket_pattern');
        if (is_null($urlTicketPattern) || empty($urlTicketPattern)) {
            // fetch the default fallback url
            $urlTicketPattern = $this->urlTicketPattern;
        }

        return sprintf($this->getURL() . $urlTicketPattern, $ticketId, $remoteId);
    }

    protected function getApi($url, $username, $password)
    {
        $this->currentUsername = $username;
        $this->api = new JiraApi(rtrim($url, '/') . '/rest/api/2/', $username, $password);
    }

    /**
     * Get issue based on ticketId.
     *
     * @param $ticketId
     * @return array
     */
    public function getIssue($ticketId)
    {
        $ticketId = ltrim($ticketId, '#');
        return $this->api->get($ticketId)->json();
    }

    public function getTitle($ticketId)
    {
        // Jira throws an exception if the issue does not exists or are
        // unreachable.
        $response = $this->getIssue($ticketId);

        if (is_array($response)) {
            return $response['fields']['summary'];
        }

        return false;
    }

    public function extractIds($string)
    {
        $ids = array();
        if (preg_match_all('/(#[A-Za-z0-9]+-\d+)/', $string, $matches)) {
            $ids = array_map('strtoupper', $matches[1]);
        }
        return array_unique($ids);
    }

    public function getTimelogEntries($ticketId)
    {
        $ticketId = ltrim($ticketId, '#');
        $response = $this->api->getFullWorklog($ticketId)->json();

        $timelogs = array();
        foreach ($response['worklogs'] as $entry) {
            $timelog = $this->parseComment($entry['comment']);
            $timelog->hours = (string) round($entry['timeSpentSeconds'] / 3600, 2);
            $timelog->started = $entry['started'];
            $timelog->spentAt = date('Y-m-d', strtotime($entry['started']));
            $timelog->remoteId = $entry['id'];
            $timelogs[] = $timelog;
        }
        return $timelogs;
    }

    public function saveTimelogEntry($ticketId, $timelog)
    {
        $ticketId = ltrim($ticketId, '#');
        // weed out newlines in notes
        $timelog->notes = preg_replace('/[\n\r]+/m', ' ', $timelog->notes);

        $worklog = new \stdClass;

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
            if ($entry->harvestId == $timelog->harvestId &&
                $entry->user      == $timelog->user      &&
                $entry->hours     == $timelog->hours     &&
                $entry->spentAt   == $timelog->spentAt   &&
                $entry->project   == $timelog->project   &&
                $entry->taskName  == $timelog->taskName  &&
                $entry->notes     == $timelog->notes) {
                return false;
            }
        }

        $worklog->comment = $this->formatComment($timelog);
        $worklog->timeSpent = $timelog->hours . 'h';

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
                    print 'Jira: Switching user to ' . $timelog->userEmail."\n";
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
     * Delete the worklog, but retain the remaining estimate
     *
     * (when auto-adjusting the removed time will be added to the remaining work)
     */
    public function deleteWorkLogEntry($worklogId, $issueId)
    {
        $issueId = ltrim($issueId, '#');
        $this->api->deleteWorklog($issueId, $worklogId);
        return true;
    }

    /**
     * A comment entry will be formatted like this:
     *
     * Entry #71791646 Kode: "Fikser #4029[tester harvest med anton]" by Rasmus Luckow-Nielsen in "BugYield test"
     */
    private function parseComment($comment)
    {
        $timelog = new \stdClass;
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

    private function formatComment($timelog)
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

    /**
     * Preparing this for JIRA, e.g. removing #hashmark, transforming "#scl-123" to "SCL-123"
     */
    public function sanitizeTicketId($ticketId)
    {
        $ticketId = trim(strtoupper(str_replace("#", "", $ticketId)));
        return $ticketId;
    }
}
