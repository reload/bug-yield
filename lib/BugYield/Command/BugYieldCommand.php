<?php

namespace BugYield\Command;

use Symfony\Component\Yaml\Yaml;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;

abstract class BugYieldCommand extends \Symfony\Component\Console\Command\Command {

	private $harvestConfig;
	private $fogbugzConfig;
	private $bugyieldConfig;
	
	/* singletons for caching data */
	private $harvestUsers = null;

	protected function configure() {
		$this->addOption('harvest-project', 'p', InputOption::VALUE_OPTIONAL, 'One or more Harvest projects (id, name or code) separated by , (comma). Use "all" for all projects.', NULL);
		$this->addOption('config', NULL, InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'config.yml');
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
		return $this->harvestConfig['projects'];
	}
	
	/**
	 * Number of days back compared to today to look for harvestentries
	 * @return Integer Number of days
	 */
	protected function getHarvestDaysBack() {
		return intval($this->harvestConfig['daysback']);
	}	

	/**
	 * Fetch url to FB
	 * @return String Url
	 */
	protected function getFogBugzURL() {
		return $this->fogbugzConfig['url'];
	}
	
  protected function getHarvestURL() {
    $http = "http://";
    if( $this->harvestConfig['ssl'] == true ) {
        $http = "https://";
    }

    return $http . $this->harvestConfig['account'] . ".harvestapp.com/";
  }

	/**
	 * Returns a connection to the FogBugz API based on the configuration.
	 * 
	 * @return \FogBugz
	 */
	protected function getFogBugzApi() {
		$fogbugz = new \FogBugz($this->fogbugzConfig['url'], $this->fogbugzConfig['username'], $this->fogbugzConfig['password']);
		$fogbugz->logon();
		return $fogbugz;
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
		$configFile = $input->getOption('config');
		if (file_exists($configFile)) {
			$config = Yaml::load($configFile);
			$this->harvestConfig = $config['harvest'];
			$this->fogbugzConfig = $config['fogbugz'];
			$this->bugyieldConfig = $config['bugyield'];

		} else {
			throw new Exception(sprintf('Missing configuration file %s', $configFile));
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

					if (sizeof(self::getTickedIds($entry)) > 0) {
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
	protected static function getTickedIds(\Harvest_DayEntry $entry) {
    $ids = array();
    if (preg_match_all('/#(\d+)/', $entry->get('notes'), $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
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

	/**
	 * Fetch the Harvest data from the FogBugz updates. 
	 *
	 * @param FogBugz_Response_Cases $response
	 * @return Array Matches from the regex preg_match
	 */
  protected function getHarvestEntriesFromFBTicket(\FogBugz_Response_Cases $response) {

    $harvestEntries = array();

    if(!isset($response->_data)) {
      error_log("no data from this response");
      return $harvestEntries;
    }

    foreach($response->_data as $case) {

      $fbId = $case->_data['ixBug'];
  		if (isset($case->_data['events'])) {
  			//Reverse the order of events to get the most recent first.
  			//These will contain the latest updates in regard to time and task.
  			$events = $case->_data['events']->_data;
  			foreach ($events as $event) {
  				$text = (isset($event->_data['sHtml'])) ? $event->_data['sHtml'] : $event->_data['s'];
  				if (is_string($text) && preg_match('/^Entry\s#([0-9]+)\s\[(.*?)\/(.*?)\]:(?:.*?)by\s(.*?)@\s([0-9-]+)\sin\s(.*?)$/', $text, $matches)) {
            $harvestEntries[$fbId][] = $matches; 
  				}
  			}
  		}
      
    }
    
    return $harvestEntries;
  }
}