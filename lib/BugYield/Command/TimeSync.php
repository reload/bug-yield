<?php

namespace BugYield\Command;

use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class TimeSync extends BugYieldCommand {

	protected function configure() {
		$this
		->setName('bugyield:timesync')
		->setAliases(array('ts', 'sync'))
		->setDescription('Sync time registration from Harvest to FogBugz')
		->setDefinition(array(
		    new InputOption('harvest-project', 'p', InputOption::VALUE_OPTIONAL, 'Harvest Project (id, name or code). Use "all" for all projects.', NULL),
		  )
		);
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->loadConfig($input);
		
		//Setup Harvest API access
    $harvest = $this->getHarvestApi();
		
		//Select Harvest projects for processing
		$output->writeln('Collecting entries from Harvest');
    $projects = array();
		$harvestProjects = ($project = $input->getOption('harvest-project')) ? $project : $this->getHarvestProjects();
		if (!is_array($harvestProjects)) {
		  $harvestProjects = array($harvestProjects);	
		}
		
		foreach ($harvestProjects as $harvestProject) {
			if (is_numeric($harvestProject)) {
	      //If numeric id then try to get a specific project 
				$result = $harvest->getProject($harvestProject);
				if ($result->isSuccess()) {
					$projects[] = $result->get('data');
				}
			} else {
				$result = $harvest->getProjects();
				if ($result->isSuccess()) {
					foreach($result->get('data') as $project) {
						if (is_string($harvestProject)) {
							//If "all" then add all projects
							if ($harvestProject == 'all') {
								$projects[] = $project;
							}
							//If string id then get project by name or shorthand (code)
							elseif ($project->get('name') == $harvestProject || $project->get('code') == $harvestProject) {
								$projects[] = $project;
							}
						}
					}
				}
			}
		}
    $output->writeln(sprintf('Collected %d projects', sizeof($projects)));
		
		//Collect ticket entries from projects
    $ticketEntries = array();
		foreach($projects as $project) {
			$range = new \Harvest_Range('19000101', date('Ymd'));
			$result = $harvest->getProjectEntries($project->get('id'), $range);
			if ($result->isSuccess()) {
				foreach ($result->get('data') as $entry) {
					if (sizeof(self::getTickedIds($entry)) > 0) {
					  $ticketEntries[] = $entry;
          }
				}
			}
		}
		$output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));

		//Update FogBugz with time registrations
		try {
			$fogbugz = $this->getFogBugzApi();
			foreach ($ticketEntries as $entry) {
        //One entry may - but shouldn't - contain multiple ticket ids
        $ticketIds = self::getTickedIds($entry);
				
        $entryText = sprintf('Entry #%d: %s (%s)', $entry->get('id'), $entry->get('notes'), $entry->get('hours'));
        //In case there are several ids in an entry then distribute the the time spent evenly
				$hoursPerTicket = round(floatval($entry->get('hours')) / sizeof($ticketIds), 2);
        
				foreach($ticketIds as $id) {
					//Get the case  with title and associated events. 
					//Limit by one to make sure we only get one.
					$response = $fogbugz->search($id, 'sTitle,hrsElapsedExtra,events', 1);
					$case = array_shift($response->_data);
					
					//Determine if the entry has already been tracked
					$alreadyTracked = false;
					if (isset($case->_data['events'])) {
						foreach ($case->_data['events']->_data as $event) {
							$text = (isset($event->_data['sHtml'])) ? $event->_data['sHtml'] : $event->_data['s'];
							if (is_string($text) && preg_match('/^Entry #'.$entry->get('id').':/', $text)) {
								$alreadyTracked = true;
								break;
							}
						}
					}
					
					if (!$alreadyTracked) {
						//Update case with new entry and time spent
            $params['token'] = $fogbugz->getToken()->_data['token'];
            $params['cmd'] = 'edit';
						$params['ixBug'] = $case->_data['ixBug'];
						$params['sEvent'] = $entryText;
						//We need to use , (comma) as seperator when reporting hours with decimals
						$params['hrsElapsedExtra'] = number_format(($case->_data['hrsElapsedExtra'] + $hoursPerTicket), 2, ',', '');
            
	          $request = new \FogBugz_Request($fogbugz);
	          $request->setParams($params);
	          $response = $request->go();
	          $output->writeln(sprintf('Updated ticket #%d: Added ‰d hours', $id, $hoursPerTicket));
	        }
				}
			}
		} catch (FogBugz_Exception $e) {
			$output->writeln('Error communicating with FogBugz: '. $e->getMessage());
		}
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
		return $ids;
	}
}