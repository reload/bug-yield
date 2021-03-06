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
        return str_pad($input, strlen($input) - mb_strlen($input, $encoding) + $pad_length, $pad_string, $pad_style);
    }

    public function stripTitles($string)
    {
        // Get all the ticket ids in string, this includes anyone which was
        // actually in a ticket title.
        $ticketIds = $this->bugtracker->extractIds($string);

        foreach ($ticketIds as $ticketId) {
            // Replace all occurrences of "<ticket id>[<something>]" with just
            // "<ticket id>". This is to avoid a cascade if a ticket title
            // contains another ticket id. See TitleSync::injectTitles() for
            // an explanation of the regex.
            $string = preg_replace(
                '/' . $ticketId . '(\\[.*?(?<!\\\\)\\])/i',
                $ticketId,
                $string
            );
        }

        return $string;
    }
}
