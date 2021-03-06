<?php

namespace BugYield\BugTracker;

use BugYield\Config;
use Exception;

abstract class BugTrackerBase implements BugTracker
{
    /**
     * Get a bugtracker instance.
     */
    public static function getInstance(Config $config): BugTracker
    {
        $bugtracker = $config->bugtracker('bugtracker') ?: $config->bugtrackerKey();
        switch ($bugtracker) {
            case 'jira':
                return new Jira($config->bugtrackerConfig());
                break;
            default:
                throw new Exception(sprintf('Unknown bugtracker %s', $name));
        }
    }
}
