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

    /**
     * Maximum number of hours in a time entry before it's considered faulty.
     */
    protected $maxHours;

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
            $config = Yaml::parseFile($configFile);
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

        // Validate max_entry_hours.
        if ($this->harvest('max_entry_hours')) {
            $this->maxHours = $this->harvest('max_entry_hours');
            // Do not allow non-numeric number of hours.
            if (!is_numeric($this->maxHours)) {
                throw new Exception(sprintf('Number of warnings %s is not a valid integer', $maxHours));
            }
        }

        $this->bugtracker = $bugtracker;
    }

    /**
     * Return extra options definition.
     *
     * For \Silly\Edition\PhpDi\Application::command()
     */
    public static function getOptions(): string
    {
        return '[--harvest-project=] [--config=] [--bugtracker=] [--debug]';
    }

    /**
     * Get options descriptions.
     *
     * For \Silly\Edition\PhpDi\Application::command()
     *
     * @return array<string>
     *
     */
    public static function getOptionsDescriptions(): array
    {
        return [
            '--harvest-project' => 'One or more Harvest projects (id, name or code) ' .
            'separated by "," (comma). Use "all" for all projects',
            '--config' => 'Path to the configuration file',
            '--bugtracker' => 'Bug Tracker to yield',
            '--debug' => 'Show debug info',
        ];
    }

    /**
     * Options defaults.
     *
     * For \Silly\Edition\PhpDi\Application::command()
     *
     * @eturn array<string>
     */
    public static function getOptionsDefaults(): array
    {
        return [
            'config' => 'config.yml',
            'bugtracker' => 'jira',
        ];
    }

    /**
     * Get debug status.
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Returns the project ids for this command from command line options or configuration.
     *
     * @return array<string> An array of project identifiers
     */
    public function getProjectIds()
    {
        $projectIds = $this->projects ?: $this->getTimetrackerProjects();
        if (!is_array($projectIds)) {
            $projectIds = explode(',', $projectIds);
            array_walk($projectIds, 'trim');
        }
        return $projectIds;
    }

    /**
     * Get timetracker projects.
     *
     * @return array<string>
     */
    public function getTimetrackerProjects(): array
    {
        return $this->bugtracker('projects');
    }

    /**
     * Get number of days of entries to work with.
     *
     * @return int
     *   Number of days
     */
    public function getDaysBack(): int
    {
        return intval($this->harvest('daysback'));
    }

    /**
     * Get max number of hours allowed on a single time entry.
     *
     * If this limit is exceeded the entry is considered potentially faulty.
     *
     * @return int/float/null The number of hours or null if not defined.
     */
    public function getMaxEntryHours()
    {
        return $this->maxHours;
    }

    /**
     * Return whether to do extended tests.
     *
     * If true we will test all referenced tickets in the bugtracker for
     * inconsistency with Harvest
     */
    public function doExtendedTest(): bool
    {
        return $this->bugtracker('extended_test') === true;
    }

    /**
     * Return whether to fix missing references.
     *
     * If true we remove any errornous references from the BugTracker to
     * Harvest, thus "fixing" Error 2.
     */
    public function fixMissingReferences(): bool
    {
        return $this->bugtracker('fix_missing_references') === true;
    }

    /**
     * Get extra email to notify if errors occur
     *
     * @todo Not really a bugtracker specific setting as such, but as
     *   timetracker and bugyield settings are global, it's in the bugtracker
     *   config for the moment being.
     *
     * @return String Url
     */
    public function getEmailNotifyOnError(): ?string
    {
        $email = null;
        if (!empty($this->bugtracker('email_notify_on_error'))) {
            $email = trim($this->bugtracker('email_notify_on_error'));
        }
        return $email;
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
