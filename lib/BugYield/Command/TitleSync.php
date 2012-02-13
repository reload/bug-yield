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
      ->setDescription('Sync ticket titles from bug tracker to Harvest');
    parent::configure();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->loadConfig($input);
    $this->getBugTrackerApi($input);

    //Setup Harvest API access
    $harvest = $this->getHarvestApi();

    $output->writeln('TitleSync executed: ' . date('Ymd H:i:s'));
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

    $ignore_locked  = true;
    $from_date      = date("Ymd",time()-(86400*$this->getHarvestDaysBack()));
    $to_date        = date("Ymd");

    $output->writeln(sprintf("Collecting Harvest entries between %s to %s",$from_date,$to_date));
    if($ignore_locked) $output->writeln("-- Ignoring entries already billed or otherwise closed.");

    $ticketEntries = $this->getTicketEntries($projects, $ignore_locked, $from_date, $to_date);

    $output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
    if (sizeof($ticketEntries) == 0) {
      //We have no entries containing ticket ids so bail
      return;
    }

    //Update Harvest entries with bug tracker ticket titles in the format [ticket-id]([ticket-title])
    try {
      foreach ($ticketEntries as $entry) {
        $update = false;

        // check for active timers - if we update the entry, then the timer will be disrupted, and odd things start to happen :-/
        if(strlen($entry->get("timer-started-at")) != 0)
          {
            // we have an active timer, bounce off!
            $output->writeln(sprintf('SKIPPED (active timer) entry %s: %s', $entry->get('id'), $entry->get('notes')));
            continue;     
          }

        //One entry may - but shouldn't - contain multiple ticket ids
        foreach ($this->getTicketIds($entry) as $ticketId) {
          //Get the case title.
          $title = $this->bugtracker->getTitle($ticketId);

          if ($title) {
            preg_match('/'.$ticketId.'(?:\[(.*?)\])?/', $entry->get('notes'), $matches);
            if (isset($matches[1])) {

              // No bugs found here yet, but I suspect that we should html_entity_code the matches array
              if ($matches[1] != $title) {
                //Entry note includes ticket title it does not match current title
                //so update it
                $entry->set('notes', preg_replace('/'.$ticketId.'(\[.*?\])/', $ticketId.'['.$title.']', $entry->get('notes')));

                $update = true;
              }
            } else {
              //Entry note does not include ticket title so add it
              $entry->set('notes', str_replace($ticketId, $ticketId.'['.$title.']', $entry->get('notes')));

              $update = true;
            }
          }
        }

        if ($update) {
          //Update the entry in Harvest
          $result = $harvest->updateEntry($entry);
          if($result->isSuccess()) {
            $output->writeln(sprintf('Updated entry %s: %s', $entry->get('id'), $entry->get('notes')));
          }
          else
            {
              $errormsg[] = sprintf('FAILED (HTTP Code: %d) to update entry %s: %s (EntryDate: %s)', $result->get('code'), $entry->get('id'), $entry->get('notes'), $entry->get('created-at'));

              foreach ($errormsg as $msg) {
                $output->writeln($msg);
                error_log(date("d-m-Y H:i:s") . " | " . $msg . "\n", 3, "error.log");
              }
            }
        }
      }
    } catch (Exception $e) {
      $output->writeln('Error communicating with bug tracker: '. $e->getMessage());
    }

    $output->writeln("TitleSync completed");
  }
}
