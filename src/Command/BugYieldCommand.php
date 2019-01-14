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
     * Return ticket entries from projects.
     *
     * @param array $projects An array of projects
     * @param boolean $ignore_locked Should we filter the closed/billed entries? We cannot update them...
     * @param string $from_date Date in YYYYMMDD format
     * @param string $to_date Date in YYYYMMDD format
     * @return array
     */
    protected function getTicketEntries($projects, $ignore_locked, $from_date, $to_date)
    {
        //Collect the ticket entries
        $ticketEntries = array();
        foreach ($projects as $project) {
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
