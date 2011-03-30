<?php

namespace BugYield\Command;

use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class TitleSync extends BugYieldCommand {

	protected function configure() {
		$this
		->setName('bugyield:titlesync')
		->setAliases(array('ts', 'titlesync'))
		->setDescription('Sync ticket titles from FogBugz to Harvest')
		->setDefinition(array(
			new InputOption('harvest-project', 'p', InputOption::VALUE_OPTIONAL, 'Harvest Project (id, name or code). Use "all" for all projects.', NULL),
		));
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->loadConfig($input);

		//Setup Harvest API access
		$harvest = $this->getHarvestApi();

		$output->writeln('Collecting entries from Harvest');

		//Select projects for processing based on input or configuration
		$projectIds = ($project = $input->getOption('harvest-project')) ? $project : $this->getHarvestProjects();
		if (!is_array($projectIds)) {
			$projectIds = array($projectIds);
		}

		$projects = $this->getProjects($projectIds);
		if (sizeof($projects) == 0) {
			//We have no projects to work with so bail
			return;
		}

		$ticketEntries = $this->getTicketEntries($projects);
		$output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
		if (sizeof($ticketEntries) == 0) {
			//We have no entries containing ticket ids so bail
			return;
		}

		//Update Harvest entries with FogBugz ticket titles in the format #[ticket-id]([ticket-title])
		try {
			$fogbugz = $this->getFogBugzApi();
			foreach ($ticketEntries as $entry) {
				$update = false;

				//One entry may - but shouldn't - contain multiple ticket ids
				foreach (self::getTickedIds($entry) as $ticketId) {
					//Get the case with title. Limit by one to make sure we only get one.
					$response = $fogbugz->search($ticketId, 'sTitle', 1);

					if ($case = array_shift($response->_data)) {
						preg_match('/#'.$ticketId.'(\[.*?\])?/', $entry->get('notes'), $matches);
						if (isset($matches[1])) {
							if ($matches[1] != $case->_data['sTitle']) {
								//Entry note includes ticket title it does not match current title
								//so update it
								$entry->set('notes', preg_replace('/#'.$ticketId.'(\[.*?\])/', '#'.$ticketId.'['.$case->_data['sTitle'].']', $entry->get('notes')));

								$update = true;
							}
						} else {
							//Entry note does not include ticket title so add it
							$entry->set('notes', str_replace('#'.$ticketId, '#'.$ticketId.'['.$case->_data['sTitle'].']', $entry->get('notes')));
							 
							$update = true;
						}
					}
				}

				if ($update) {
					//Update the entry in Harvest
					$harvest->updateEntry($entry);

					$output->writeln(sprintf('Updated entry #%d: %s', $entry->get('id'), $entry->get('notes')));
				}
			}
		} catch (FogBugz_Exception $e) {
			$output->writeln('Error communicating with FogBugz: '. $e->getMessage());
		}
	}
}