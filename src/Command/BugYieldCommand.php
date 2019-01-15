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
        return str_pad($input, strlen($input)-mb_strlen($input, $encoding)+$pad_length, $pad_string, $pad_style);
    }
}
