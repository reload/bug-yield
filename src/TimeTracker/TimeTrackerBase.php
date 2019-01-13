<?php

namespace BugYield\TimeTracker;

use BugYield\Config;
use Exception;

abstract class TimeTrackerBase implements TimeTracker
{
    /**
     * Get a bugtracker instance.
     */
    public static function getInstance(Config $config)
    {
        // Currently hardcoded to Harvest.
        return new Harvest($config->timetrackerConfig());
    }
}
