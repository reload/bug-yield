<?php

namespace BugYield\Command;

use Symfony\Component\Yaml\Yaml;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;

abstract class BugYieldCommand extends \Symfony\Component\Console\Command\Command {

  private $harvestConfig;
  private $bugyieldConfig;
  private $bugtrackerConfig;
  protected $bugtracker;
  private $debug;

  /* singletons for caching data */
  private $harvestUsers = null;

  protected function configure() {
    $this->addOption('harvest-project', 'p', InputOption::VALUE_OPTIONAL, 'One or more Harvest projects (id, name or code) separated by , (comma). Use "all" for all projects.', NULL);
    $this->addOption('config', NULL, InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'config.yml');
    $this->addOption('bugtracker', NULL, InputOption::VALUE_OPTIONAL, 'Bug Tracker to yield', 'fogbugz');
    $this->addOption('debug', NULL, InputOption::VALUE_OPTIONAL, 'Show debug info', false);
  }

  /**
   * Returns a connection to the Harvest API based on the configuration.
   * 
   * @return \HarvestAPI
   */
  protected function getHarvestApi() {
    $harvest = new \HarvestAPI();
    $harvest->setAccount($this->harvestConfig['account']);
    $harvest->setUser($this->harvestConfig['username']);
    $harvest->setPassword($this->harvestConfig['password']);
    $harvest->setSSL($this->harvestConfig['ssl']);
    return $harvest;
  }

  protected function getHarvestProjects() {
    return $this->bugtrackerConfig['projects'];
  }
        
  /**
   * Number of days back compared to today to look for harvestentries
   * @return Integer Number of days
   */
  protected function getHarvestDaysBack() {
    return intval($this->harvestConfig['daysback']);
  }     

  /**
   * Fetch url to the bugtracker
   * @return String Url
   */
  protected function getBugtrackerURL() {
    return $this->bugtrackerConfig['url'];
  }

  /**
   * Create direct url to ticket
   *
   * @param String $ticketId ID of ticket, eg "4564" or "SCL-34"
   * @param Integer $remoteId EventID of the exact worklog item/comment, eg "12344"
   * @return String Url
   */
  protected function getBugtrackerTicketURL($ticketId, $remoteId = null) {

    $urlTicketPattern = $this->bugtrackerConfig['url_ticket_pattern'];
    if(is_null($urlTicketPattern) || empty($urlTicketPattern))
    {
      // fetch the default fallback url
      $urlTicketPattern = $this->bugtracker->getUrlTicketPattern();
    }

    return sprintf($this->bugtrackerConfig['url'] . $urlTicketPattern, $ticketId, $remoteId);
  }

  protected function getHarvestURL() {
    $http = "http://";
    if( $this->harvestConfig['ssl'] == true ) {
      $http = "https://";
    }

    return $http . $this->harvestConfig['account'] . ".harvestapp.com/";
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
   * Returns a connection to the FogBugz API based on the configuration.
   * 
   * @return \FogBugz
   */
  protected function getBugTrackerApi(InputInterface $input) {
    // The bugtracker system is defined in the config. As a fallback
    // we use the config section label as bugtracker system
    // identifier.
    if (isset($this->bugtrackerConfig['bugtracker'])) {
        $bugtracker =  $this->bugtrackerConfig['bugtracker'];
      } else {
        $bugtracker = $input->getOption('bugtracker');
      }
    switch ($bugtracker) {
    case 'jira':
      $this->bugtracker = new \JiraBugTracker;
      break;
    case 'fogbugz':
    default:
      $this->bugtracker = new \FogBugzBugTracker;
      break;
    }

    $this->bugtracker->getApi($this->bugtrackerConfig['url'], $this->bugtrackerConfig['username'], $this->bugtrackerConfig['password']);
  }

  protected function getBugyieldEmailFrom() {
    return $this->bugyieldConfig["email_from"];
  }

  protected function getBugyieldEmailFallback() {
    return $this->bugyieldConfig["email_fallback"];
  }

  /**
   * Loads the configuration from a yaml file
   * 
   * @param InputInterface $input
   * @throws Exception
   */
  protected function loadConfig(InputInterface $input) {
    // enable debug?
    $this->debug = $input->getOption('debug');

    $configFile = $input->getOption('config');
    if (file_exists($configFile)) {
      $config = Yaml::load($configFile);
      $this->harvestConfig = $config['harvest'];
      $this->bugyieldConfig = $config['bugyield'];

      if(isset($config[$input->getOption('bugtracker')])) {
        $this->bugtrackerConfig = $config[$input->getOption('bugtracker')];
      }
      else
      {
        throw new \Exception(sprintf('Configuration file error: Uknown bugtracker label "%s"', $input->getOption('bugtracker')));
      }

    } else {
      throw new \Exception(sprintf('Missing configuration file %s', $configFile));
    }
  }

  /**
   * Returns the project ids for this command from command line options or configuration.
   * 
   * @param InputInterface $input
   * @return array An array of project identifiers
   */
  protected function getProjectIds(InputInterface $input) {
    $projectIds = ($project = $input->getOption('harvest-project')) ? $project : $this->getHarvestProjects();
    if (!is_array($projectIds)) {
      $projectIds = explode(',', $projectIds);
      array_walk($projectIds, 'trim');
    }
    return $projectIds;
  }

  /**
   * Collect projects from Harvest
   *
   * @param array $projectIds An array of project identifiers - ids, names or codes
   */
  protected function getProjects($projectIds) {
    $projects = array();

    //Setup Harvest API access
    $harvest = $this->getHarvestApi();

    //Prepare by getting all projects
    $result = $harvest->getProjects();
    $harvestProjects = ($result->isSuccess()) ? $result->get('data') : array();

    //Collect all requested projects
    $unknownProjectIds = array();
    foreach ($projectIds as $projectId) {
      if (is_numeric($projectId)) {
        //If numeric id then try to get a specific project
        $result = $harvest->getProject($projectId);
        if ($result->isSuccess()) {
          $projects[] = $result->get('data');
        } else {
          $unknownProjectIds[] = $projectId;
        }
      } else {
        $identified = false;
        foreach($harvestProjects as $project) {
          if (is_string($projectId)) {
            //If "all" then add all projects
            if ($projectId == 'all') {
              $projects[] = $project;
              $identified = true;
            }
            //If string id then get project by name or shorthand (code)
            elseif ($project->get('name') == $projectId || $project->get('code') == $projectId) {
              $projects[] = $project;
              $identified = true;
            }
          }
        }
        if (!$identified) {
          $unknownProjectIds[] = $projectId;
        }
      }
    }
    return $projects;
  }

  /**
   * Collect users from Harvest
   *
   */
  protected function getUsers() {

    if(is_array($this->harvestUsers))
      {
        return $this->harvestUsers;
      }  

    //Setup Harvest API access
    $harvest = $this->getHarvestApi();

    //Prepare by getting all projects
    $result = $harvest->getUsers();
    $harvestUsers = ($result->isSuccess()) ? $result->get('data') : array();

    $this->harvestUsers = $harvestUsers;

    // Array of Harvest_User objects
    return $harvestUsers;

  }

  /**
   * Return ticket entries from projects.
   *
   * @param array $projects An array of projects
   * @param boolean $ignore_locked Should we filter the closed/billed entries? We cannot update them...
   * @param Integer $from_date Date in YYYYMMDD format
   * @param Integer $to_date Date in YYYYMMDD format  
   */
  protected function getTicketEntries($projects, $ignore_locked = true, $from_date = null, $to_date = null) {
    //Setup Harvest API access
    $harvest = $this->getHarvestApi();
                 
    //Collect the ticket entries
    $ticketEntries = array();
    foreach($projects as $project) {
                  
      if(!is_numeric($from_date)) {
        $from_date = "19000101";
      }

      if(!is_numeric($to_date)) {
        $to_date = date('Ymd');
      }

      $range = new \Harvest_Range($from_date, $to_date);

      $result = $harvest->getProjectEntries($project->get('id'), $range);
      if ($result->isSuccess()) {
        foreach ($result->get('data') as $entry) {

          // check that the entry is actually writeable
          if($ignore_locked == true && ($entry->get("is-closed") == "true" || $entry->get("is-billed") == "true")) {
            continue;
          }

          if (sizeof($this->getTicketIds($entry)) > 0) {
            //If the entry has ticket ids it is a ticket entry
            $ticketEntries[] = $entry;
          }
        }
      }
    }

    return $ticketEntries;
  }

  /**
   * Extract ticket ids from entries if available
   * @param \Harvest_DayEntry $entry
   * @return array Array of ticket ids
   */
  protected function getTicketIds(\Harvest_DayEntry $entry) {
    return $this->bugtracker->extractIds($entry->get('notes'));
  }

  /**
   * Look through the projects array and return a name
   * @param Array $projects array of Harvest_Project objects
   * @param Integer $projectId 
   * @return String Name of the project
   */  
  protected static function getProjectNameById($projects,$projectId) {
    $projectName = "Unknown";
    foreach ($projects as $project) {
      if($project->get("id") == $projectId) {
        $projectName = $project->get("name");
        break;
      }
    }
    return $projectName;
  }

  /**
   * Fetch the Harvest User by id
   * @param Integer $harvest_user_id 
   * @return String Full name
   */
  protected function getUserNameById($harvest_user_id) {
    $username = "Unknown";
    
    $harvestUsers = $this->getUsers();
    
    if(isset($harvestUsers[$harvest_user_id])) {
      $Harvest_User = $harvestUsers[$harvest_user_id];
      $username = $Harvest_User->get("first-name") . " " . $Harvest_User->get("last-name");
    }

    return $username;    
  }  
  

  /**
   * Fetch the Harvest User Email by id
   * @param Integer $harvest_user_id 
   * @return String Full name
   */
  protected function getUserEmailById($harvest_user_id) {
    $email = self::getBugyieldEmailFallback();
    
    $harvestUsers = $this->getUsers();
    
    if(isset($harvestUsers[$harvest_user_id])) {
      $Harvest_User = $harvestUsers[$harvest_user_id];
      $email = $Harvest_User->get("email");
    }

    return $email;    
  }  
  

  /**
   * Fetch the Harvest Entry by id
   * @param Integer $harvestEntryId
   * @param Integer $harvest_user_id      
   * @return Harvest_Entry Entry object
   */
  protected function getEntryById($harvestEntryId, $user_id = false) {
    $harvest = $this->getHarvestApi();
    $entry = false;
    
    $result = $harvest->getEntry($harvestEntryId, $user_id);
    
    if ($result->isSuccess()) {
      $entry = $result->get('data');
    }
    
    return $entry;
    
  }

  /**
   * Fetch the Harvest user by searching for the full name
   * This will of course make odd results if you have two or more active users with exactly the same name...
   *
   * @param String $fullname 
   * @return Harvest_User User object
   */  
  protected function getHarvestUserByFullName($fullname) {
    $user = false;
    $fullname = trim($fullname);
    
    foreach($this->getUsers() as $Harvest_User) {
      // only search for active users.
      // prey that you do not have two users with identical names. TODO this is a possible bug in spe
      if($Harvest_User->get("is-active") == "false") {
        continue;
      }

      $tmpFullName = trim($Harvest_User->get("first-name") . " " . $Harvest_User->get("last-name"));
      if($fullname == $tmpFullName) {
        // yay, we have a winner! :-)
        $user = $Harvest_User;
        break;
      } 
    }

    return $user;
  }

  // if debug is enabled by --debug=true in cmd, then print the statements
  protected function debug($data) {
    if($this->debug == true) {
      print $data;
    }
  }

  // little helper function for multibyte string padding
  protected function mb_str_pad ($input, $pad_length, $pad_string, $pad_style = STR_PAD_RIGHT, $encoding="UTF-8") {
     return str_pad($input, strlen($input)-mb_strlen($input,$encoding)+$pad_length, $pad_string, $pad_style);
  }
}
