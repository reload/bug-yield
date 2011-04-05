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
		->setAliases(array('tim', 'timesync'))
		->setDescription('Sync time registration from Harvest to FogBugz');
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->loadConfig($input);

		//Setup Harvest API access
		$harvest = $this->getHarvestApi();
		
		$output->writeln('Verifying projects in Harvest');

		$projects = $this->getProjects($this->getProjectIds($input));
		if (sizeof($projects) == 0) {
			//We have no projects to work with so bail
			$output->writeln(sprintf('Could not find any projects matching: %s', $input));
			return;
		}

		foreach ($projects as $Harvest_Project) {
		  $output->writeln(sprintf('Working with project: %s', $Harvest_Project->get("name")));
		}

		$output->writeln('Collecting entries from Harvest');
	
		$ticketEntries = $this->getTicketEntries($projects);	 
		$output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
		if (sizeof($ticketEntries) == 0) {
			//We have no entries containing ticket ids so bail
			return;
		}

		//Update FogBugz with time registrations
		try {
			$fogbugz = $this->getFogBugzApi();
			foreach ($ticketEntries as $entry) {
				//One entry may - but shouldn't - contain multiple ticket ids
				$ticketIds = self::getTickedIds($entry);

				//Determine task
				$response = $harvest->getTask($entry->get('task-id'));
				$taskName = ($response->isSuccess()) ? $response->get('data')->get('name') : 'Unknown';

				$entryText = sprintf('Entry #%d [%s/%s]: %s', $entry->get('id'), $entry->get('hours'), $taskName, $entry->get('notes'));

				//In case there are several ids in an entry then distribute the the time spent evenly
				$hoursPerTicket = round(floatval($entry->get('hours')) / sizeof($ticketIds), 2);

				foreach($ticketIds as $id) {
					//Get the case  with title and associated events.
					//Limit by one to make sure we only get one.
					$response = $fogbugz->search($id, 'sTitle,hrsElapsedExtra,events', 1);
					$case = array_shift($response->_data);

					//Copy the entry text for the ticket. We may need to manipulate it further before posting
					$ticketText = $entryText;

					//Calculate the total number of hours for the ticket
					$totalHours = $case->_data['hrsElapsedExtra'] + $hoursPerTicket;

					//Determine if the entry has already been tracked
					$alreadyTracked = false;
					if (isset($case->_data['events'])) {
						//Reverse the order of events to get the most recent first.
						//These will contain the latest updates in regard to time and task.
						$events = array_reverse($case->_data['events']->_data);
						foreach ($events as $event) {
							$text = (isset($event->_data['sHtml'])) ? $event->_data['sHtml'] : $event->_data['s'];
							if (is_string($text) && preg_match('/^Entry\s#'.$entry->get('id').'\s\[(.*)\/(.*)\]:/', $text, $matches)) {
								//Entry has already been tracked. Determine if data has been updated in Harvest since
								if ($matches[1] == $entry->get('hours') &&
								$matches[2] == $taskName) {
									$alreadyTracked = true;
								} else {
									//Show that the entry has been updated
									$ticketText .= ' (updated)';

									//Entry has already been tracked but number of hours have been updated
									//so we need to subtract the previously entered number of hours.
									$totalHours -= $matches[1];
								}
								break;
							}
						}
					}

					if (!$alreadyTracked) {
						//Update case with new or updated entry and time spent
						$params['token'] = $fogbugz->getToken()->_data['token'];
						$params['cmd'] = 'edit';
						$params['ixBug'] = $case->_data['ixBug'];
						$params['sEvent'] = $ticketText;
						//We need to use , (comma) instead of . (period) as seperator when reporting
						//hours with decimals. Silly FogBugz.
						$params['hrsElapsedExtra'] = number_format($totalHours, 2, ',', '');

						$request = new \FogBugz_Request($fogbugz);
						$request->setParams($params);
						$response = $request->go();
						if ($response instanceof \FogBugz_Response_Case) {
							$output->writeln(sprintf('Updated ticket #%d: Now at %.2f hours', $id, $totalHours));
						} else {
							$output->writeln(sprintf('Error: Unable to update ticked #%d', $id));
						}
					}
				}
			}
		} catch (FogBugz_Exception $e) {
			$output->writeln('Error communicating with FogBugz: '. $e->getMessage());
		}
	}

}