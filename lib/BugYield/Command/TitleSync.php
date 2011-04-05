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
		->setAliases(array('tit', 'titlesync'))
		->setDescription('Sync ticket titles from FogBugz to Harvest');
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
					$result = $harvest->updateEntry($entry);
          if($result->isSuccess()) {
            $output->writeln(sprintf('Updated entry #%d: %s', $entry->get('id'), $entry->get('notes')));
          }
          else
          {
            $errormsg[] = sprintf('FAILED (HTTP Code: %d) to update entry #%d: %s (EntryDate: %s)', $result->get('code'), $entry->get('id'), $entry->get('notes'), $entry->get('created-at'));

            foreach ($errormsg as $msg) {
               $output->writeln($msg);
               error_log(date("d-m-Y H:i:s") . " | " . $msg . "\n", 3, "error.log");
            }
          }
				}
			}
		} catch (FogBugz_Exception $e) {
			$output->writeln('Error communicating with FogBugz: '. $e->getMessage());
		}
	}
}