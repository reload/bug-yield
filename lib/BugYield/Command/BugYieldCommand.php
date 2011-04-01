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
		$harvest->setSSL($this->harvestConfig['account']);
		return $harvest;
	}

	protected function getHarvestProjects() {
		return $this->harvestConfig['projects'];
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
	 * Return ticket entries from projects.
	 *
	 * @param array $projects An array of projects
	 */
	protected function getTicketEntries($projects) {
		//Setup Harvest API access
		$harvest = $this->getHarvestApi();
		 
		//Collect the ticket entries
		$ticketEntries = array();
		foreach($projects as $project) {
			$range = new \Harvest_Range('19000101', date('Ymd'));
			$result = $harvest->getProjectEntries($project->get('id'), $range);
			if ($result->isSuccess()) {
				foreach ($result->get('data') as $entry) {
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
}