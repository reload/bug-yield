<?php

namespace BugYield;

use BugYield\Command\TitleSync;
use BugYield\Command\TimeSync;
use Symfony\Component\Yaml\Yaml;
use Silly\Edition\PhpDi\Application;

class BugYield extends Application
{

    public function __construct()
    {
        parent::__construct('Bug Yield', '1.0');

        $commonOptions = '[--harvest-project=] [--config=] [--bugtracker=] [--debug]';
        $commonOptionsDescs = [
            '--harvest-project' => 'One or more Harvest projects (id, name or code) separated by "," (comma). Use "all" for all projects',
            '--config' => 'Path to the configuration file',
            '--bugtracker' => 'Bug Tracker to yield',
            '--debug' => 'Show debug info',
        ];
        $commonOptionsDefaults = [
            'config' => 'config.yml',
            'bugtracker' => 'jira',
        ];

        $this->command('timesync ' . $commonOptions, TimeSync::class)
            ->descriptions('Sync time registration from Harvest to bug tracker', $commonOptionsDescs)
            ->defaults($commonOptionsDefaults);

        $this->command('titlesync ' . $commonOptions, TitleSync::class)
            ->descriptions('Sync ticket titles from bug tracker to Harvest', $commonOptionsDescs)
            ->defaults($commonOptionsDefaults);
    }
}
