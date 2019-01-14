<?php

namespace BugYield\Command;

use BugYield\BugTracker\BugTracker;
use BugYield\TimeTracker\TimeTracker;
use BugYield\Config;

use Harvest\Model\DayEntry;

use Symfony\Component\Console\Input\InputInterface;

abstract class BugYieldCommand
{
    /**
     * @var Config
     */
    protected $config;

    protected $bugtracker;
    protected $timetracker;

    /* singletons for caching data */
    private $harvestUsers = null;
    private $harvestTasks = null;

    public function __construct(Config $config, BugTracker $bugtracker, TimeTracker $timetracker)
    {
        $this->config = $config;
        $this->bugtracker = $bugtracker;
        $this->timetracker = $timetracker;
    }

    protected function getBugtracker()
    {
        return $this->bugtracker;
    }

    protected function getTimetracker()
    {
        return $this->timetracker;
    }

    /**
     * Create direct url to ticket
     *
     * @todo move to BugTracker.
     *
     * @param String $ticketId ID of ticket, eg "4564" or "SCL-34"
     * @param Integer $remoteId EventID of the exact worklog item/comment, eg "12344"
     * @return String Url
     */
    protected function getBugtrackerTicketURL($ticketId, $remoteId = null)
    {

        $urlTicketPattern = $this->config->bugtracker('url_ticket_pattern');
        if (is_null($urlTicketPattern) || empty($urlTicketPattern)) {
            // fetch the default fallback url
            $urlTicketPattern = $this->getBugtracker()->getUrlTicketPattern();
        }

        return sprintf($this->config->bugtracker('url') . $urlTicketPattern, $ticketId, $remoteId);
    }

    /**
     * Return ticket entries from projects.
     *
     * @param array $projects An array of projects
     * @param boolean $ignore_locked Should we filter the closed/billed entries? We cannot update them...
     * @param Integer $from_date Date in YYYYMMDD format
     * @param Integer $to_date Date in YYYYMMDD format
     * @return array
     */
    protected function getTicketEntries($projects, $ignore_locked = true, $from_date = null, $to_date = null)
    {
        //Collect the ticket entries
        $ticketEntries = array();
        foreach ($projects as $project) {
            if (!is_numeric($from_date)) {
                $from_date = "19000101";
            }

            if (!is_numeric($to_date)) {
                $to_date = date('Ymd');
            }

            $entries = $this->timetracker->getProjectEntries($project->get('id'), $ignore_locked, $from_date, $to_date);
            foreach ($entries as $entry) {
                $ids = $this->getTicketIds($entry);
                if (sizeof($ids) > 0) {
                    //If the entry has ticket ids it is a ticket entry.
                    $ticketEntries[] = $entry;
                }
            }
        }

        return $ticketEntries;
    }

    /**
     * Extract ticket ids from entries if available
     *
     * @todo Move to BugTracker or let commands make BugTracker and
     *   TimeTracker talk together.
     *
     * @param DayEntry $entry
     * @return array Array of ticket ids
     */
    protected function getTicketIds(DayEntry $entry)
    {
        return $this->getBugtracker()->extractIds($entry->get('notes'));
    }

    // mail wrapper - prepared for enabling debug info
    protected function mail($to, $subject, $body, $headers)
    {
        $this->debug($subject);
        $this->debug($body);
        $this->debug("\n --- EOM ---\n");
        return mail($to, $subject, $body, $headers);
    }

    // if debug is enabled by --debug=true in cmd, then print the statements
    protected function debug($data)
    {
        if ($this->config->isDebug()) {
            print_r($data);
        }
    }

    // little helper function for multibyte string padding
    protected function mbStrPad($input, $pad_length, $pad_string, $pad_style = STR_PAD_RIGHT, $encoding = "UTF-8")
    {
        return str_pad($input, strlen($input)-mb_strlen($input, $encoding)+$pad_length, $pad_string, $pad_style);
    }
}
