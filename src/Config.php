<?php

namespace BugYield;

use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

class Config
{
    protected $debug = false;
    /**
     * Projects to work on.
     *
     * Comma separated string.
     */
    protected $projects;

    protected $harvestConfig;
    protected $bugyieldConfig;
    protected $bugtrackerConfig;
    protected $bugtracker;

    public function __construct(InputInterface $input)
    {
        $this->debug = (bool) $input->getOption('debug');
        if ($input->hasOption('harvest-project')) {
            $this->projects = $input->getOption('harvest-project');
        }

        $configFile = $input->getOption('config');
        $bugtracker = $input->getOption('bugtracker');

        if (file_exists($configFile)) {
            $config = Yaml::parse($configFile);
            $this->harvestConfig = $config['harvest'];
            $this->bugyieldConfig = $config['bugyield'];
            if (isset($config[$bugtracker])) {
                $this->bugtrackerConfig = $config[$bugtracker];
            } else {
                throw new Exception(sprintf(
                    'Configuration file error: Unknown bugtracker label "%s"',
                    $bugtracker
                ));
            }
        } else {
            throw new Exception(sprintf('Missing configuration file %s', $configFile));
        }

        $this->bugtracker = $bugtracker;
    }

    /**
     * Extra options definition.
     */
    public static function getOptions()
    {
        return '[--harvest-project=] [--config=] [--bugtracker=] [--debug]';
    }

    /**
     * Options descriptions.
     */
    public static function getOptionsDescriptions()
    {
        return [
            '--harvest-project' => 'One or more Harvest projects (id, name or code) separated by "," (comma). Use "all" for all projects',
            '--config' => 'Path to the configuration file',
            '--bugtracker' => 'Bug Tracker to yield',
            '--debug' => 'Show debug info',
        ];
    }

    /**
     * Options defaults.
     */
    public static function getOptionsDefaults()
    {
        return [
            'config' => 'config.yml',
            'bugtracker' => 'jira',
        ];
    }

    /**
     * Get debug status.
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * Returns the project ids for this command from command line options or configuration.
     *
     * @param InputInterface $input
     * @return array An array of project identifiers
     */
    public function getProjectIds(InputInterface $input)
    {
        $projectIds = $this->projects ?: $this->getTimetrackerProjects();
        if (!is_array($projectIds)) {
            $projectIds = explode(',', $projectIds);
            array_walk($projectIds, 'trim');
        }
        return $projectIds;
    }

    public function getTimetrackerProjects()
    {
        return $this->bugtracker('projects');
    }

    public function harvest($key)
    {
        return $this->harvestConfig[$key] ?: null;
    }

    public function bugyield($key)
    {
        return $this->bugyieldConfig[$key] ?: null;
    }

    public function bugtracker($key)
    {
        return $this->bugtrackerConfig[$key] ?: null;
    }

    public function bugtrackerKey()
    {
        return $this->bugtracker;
    }

    public function bugtrackerConfig()
    {
        return $this->bugtrackerConfig;
    }

    public function timetrackerConfig()
    {
        return $this->harvestConfig;
    }
}
