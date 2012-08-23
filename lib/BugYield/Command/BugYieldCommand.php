<?php

namespace BugYield\Command;

use Symfony\Component\Yaml\Yaml;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BugYieldCommand extends Command {

  private $bugyieldConfig;

  protected $bugtracker;
  private $bugtrackerConfig;
  
  protected $timetracker;
  private $timetrackerConfig;
  
  private $debug;
  private $output;

  /* singletons for caching data */
  private $harvestUsers = null;

  protected function configure() {
    $this->addOption('timetracker', NULL, InputOption::VALUE_OPTIONAL, 'Time tracker to yield', 'harvest');
    $this->addOption('timetracker-projects', NULL, InputOption::VALUE_OPTIONAL, 'One or more time tracker projects (id, name or code) separated by , (comma). Use "all" for all projects.', NULL);
    $this->addOption('config', NULL, InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'config.yml');
    $this->addOption('bugtracker', NULL, InputOption::VALUE_OPTIONAL, 'Bug Tracker to yield', 'fogbugz');
  }

  /**
   * Shared initialization for all commands.
   * 
   * @param  InputInterface  $input
   * @param  OutputInterface $output
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    // Store the inooutput for future reference
    $this->output = $output;
    $this->input = $input;

    // Load the YAML configuration
    $this->loadConfig($input);

    // Setup systems
    $this->setupBugTracker($input);  
    $this->setupTimetracker($input);

    // Validate status
    $this->validate($output);
  }

  /**
   * Loads the configuration from a yaml file
   * 
   * @param InputInterface $input
   * @throws Exception
   */
  protected function loadConfig(InputInterface $input) {
    $configFile = $input->getOption('config');
    if (file_exists($configFile)) {
      $config = Yaml::load($configFile);
      $this->bugyieldConfig = $config['bugyield'];

      foreach (array('bugtracker', 'timetracker') as $system) {
        if (isset($config[$input->getOption($system)])) {
          $this->{$system . 'Config'}  = $config[$input->getOption($system)];
        } else {
          throw new \Exception(sprintf('Configuration file error: Uknown %s label "%s"', $system, $input->getOption($system)));
        }        
      }
    } else {
      throw new \Exception(sprintf('Missing configuration file %s', $configFile));
    }
  }

  /**
   * Setup a connection to the time tracker based on the configuration.
   */
  protected function setupTimetracker(InputInterface $input) {
    // The time tracker system is defined in the config. As a fallback
    // we use the config section label as bugtracker system
    // identifier.
    if (isset($this->timetrackerConfig['timetracker'])) {
      $timetracker = $this->timetrackerConfig['timetracker'];
    } else {
      $timetracker = $input->getOption('timetracker');
    }

    // First try to guess the classname using shorthands
    // If that is not correct assume that the system name is the
    // full class name
    $timetrackerClass = "BugYield\TimeTracker\\" . ucfirst($timetracker) . "\\TimeTracker";
    if (!class_exists($timetrackerClass)) {
      $timetrackerClass = $timetracker;
    }
    $timetrackerClass = new \ReflectionClass($timetrackerClass);
    $this->timetracker = $timetrackerClass->newInstance($this->timetrackerConfig);
  }

  /**
   * Returns a connection to the FogBugz API based on the configuration.
   * 
   * @return \FogBugz
   */
  protected function setupBugTracker(InputInterface $input) {
    // The bugtracker system is defined in the config. As a fallback
    // we use the config section label as bugtracker system
    // identifier.
    if (isset($this->bugtrackerConfig['bugtracker'])) {
      $bugtracker =  $this->bugtrackerConfig['bugtracker'];
    } else {
      $bugtracker = $input->getOption('bugtracker');
    }

    // First try to guess the classname for the bug tracker
    // If that is not correct assume that the system name is the
    // full class name
    $bugtrackerClass = "BugYield\BugTracker\\" . ucfirst($bugtracker) . "\\BugTracker";
    if (!class_exists($bugtrackerClass)) {
      $bugtrackerClass = $bugtracker;
    }
    $bugtrackerClass = new \ReflectionClass($bugtrackerClass);
    $this->bugtracker = $bugtrackerClass->newInstance($this->bugtrackerConfig);
  }

  protected function validate(OutputInterface $output) {
    // Initial info
    $this->log($this->getName() . ' executing: ' . date('Ymd H:i:s'));
    $this->log(sprintf('Timetracker: %s (%s)', $this->timetracker->getName(), $this->timetracker->getUrl()));
    $this->log(sprintf('Bugtracker: %s (%s)', $this->bugtracker->getName(), $this->bugtracker->getUrl()));

    $this->log('Verifying projects in Harvest');
    $projects = $this->timetracker->getProjects();
    if (sizeof($projects) == 0) {
      // We have no projects to work with so bail
      // TODO: We need to bail harder here. Throw an exception perhaps?
      $this->log(sprintf('Could not find any projects matching: %s', $input));
      return;
    }

    foreach ($projects as $project) {
      $archivedText = "";
      if (!$project->isActive()) {
        $archivedText = sprintf("ARCHIVED (Latest activity: %s)", $project->getLatestActivity());
      }
      $this->log(sprintf('Working with project: %s %s %s', self::mb_str_pad($project->getName(), 40, " "), self::mb_str_pad($project->getCode(), 18, " "), $archivedText));
    }

    $ignoreLocked   = TRUE;
    $fromDate       = date("Ymd", time() - (86400 * $this->getTimetrackerDaysBack()));
    $toDate         = date("Ymd");

    $this->log(sprintf("Collecting Harvest entries between %s to %s",$fromDate,$toDate));
    if ($ignoreLocked) {
      $this->log("-- Ignoring entries already billed or otherwise closed.");
    }    
  }

  // Common utility functions.

  /**
   * Return ticket entries from projects.
   *
   * @param int     $fromDate     Date in YYYYMMDD format
   * @param int     $toDate       Date in YYYYMMDD format  
   * @param boolean $ignoreLocked Should we filter the closed/billed entries? We cannot update them...
   */
  protected function getTicketEntries($fromDate = NULL, $toDate = NULL, $ignoreLocked = TRUE) {
    $ticketEntries = array();

    //Collect the ticket entries
    $entries = $this->timetracker->getEntries($fromDate, $toDate, $ignoreLocked);
    foreach ($entries as $entry) {
      if (sizeof($this->bugtracker->extractTicketIds($entry->getText())) > 0) {
        //If the entry has ticket ids it is a ticket entry
        $ticketEntries[] = $entry;
      }
    }

    return $ticketEntries;
  }
  
  protected function log($string, $level = LOG_INFO, $line = TRUE) {
    if ($level != LOG_DEBUG ||
        $this->input->getOption('verbose') == true) {
      if ($line) {
        $this->output->writeln($string);
      } else {
        $this->output->write($string);
      }
    }
  }

  // Configuration retrieval functions. Should these be refactored.

  protected function getBugyieldEmailFrom() {
    return $this->bugyieldConfig["email_from"];
  }

  protected function getBugyieldEmailFallback() {
    return $this->bugyieldConfig["email_fallback"];
  } 

  /**
   * Fetch email of email to notify extra if errors occur
   * @return String Url
   */
  protected function getEmailNotifyOnError() {
    $email = null;
    if(isset($this->bugtrackerConfig['email_notify_on_error']) && !empty($this->bugtrackerConfig['email_notify_on_error'])) {
      $email = trim($this->bugtrackerConfig['email_notify_on_error']);
    }
    return $email;
  }

  /**
   * Check value of config setting "extended_test".
   * If true we will test all referenced tickets in the bugtracker for inconsistency with Harvest
   */
  protected function doExtendedTest() {

    if(isset($this->bugtrackerConfig['extended_test'])) {
      if($this->bugtrackerConfig['extended_test'] === true) {
        return true;
      }
    }
    return false;
  }

  /**
   * Number of days back compared to today to look for harvestentries
   * @return Integer Number of days
   */
  protected function getTimeTrackerDaysBack() {
    return intval($this->timetrackerConfig['daysback']);
  }    

  /**
   * Max number of hours allowed on a single time entry. If this limit is
   * exceeded the entry is considered potentially faulty.
   * 
   * @return int/float/null The number of hours or NULL if not defined.
   */
  protected function getMaxEntryHours() {
    $maxHours = NULL;
    if (isset($this->harvestConfig['max_entry_hours'])) {
      $maxHours = $this->harvestConfig['max_entry_hours'];
      // Do not allow non-numeric number of hours
      if (!is_numeric($maxHours)) {
        $this->log(sprintf('Number of warnings %s is not a valid integer', $maxHours), LOG_DEBUG);
        $maxHours = NULL;
      }
    }
    return $maxHours;
  } 

  // little helper function for multibyte string padding
  protected function mb_str_pad ($input, $pad_length, $pad_string, $pad_style = STR_PAD_RIGHT, $encoding="UTF-8") {
     return str_pad($input, strlen($input)-mb_strlen($input,$encoding)+$pad_length, $pad_string, $pad_style);
  }
}
