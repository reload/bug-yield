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
    $output->writeln(sprintf('Bugtracker is %s (%s)', $this->bugtracker->getName(), $this->getBugtrackerURL()));
    $output->writeln('Verifying projects in Harvest');

    $projects = $this->getProjects($this->getProjectIds($input));
    if (sizeof($projects) == 0) {
      //We have no projects to work with so bail
      if(!isset($input) || !is_string($input)) { $input = "ARGUMENT IS NULL"; }
      $output->writeln(sprintf('Could not find any projects matching: %s', $input));
      return;
    }

    foreach ($projects as $Harvest_Project) {
      $archivedText = "";
      if($Harvest_Project->get("active") == "false") {
        $archivedText = sprintf("ARCHIVED (Latest activity: %s)", $Harvest_Project->get("hint-latest-record-at"));
      }
      $output->writeln(sprintf('Working with project: %s %s %s', self::mb_str_pad($Harvest_Project->get("name"), 40, " "), self::mb_str_pad($Harvest_Project->get("code"), 18, " "), $archivedText));
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
        $this->debug(".");

        // check for active timers - if we update the entry, then the timer will be disrupted, and odd things start to happen :-/
        if(strlen($entry->get("timer-started-at")) != 0)
          {
            // we have an active timer, bounce off!
            $this->debug("\n");
            $output->writeln(sprintf('SKIPPED (active timer) entry %s: %s', $entry->get('id'), $entry->get('notes')));
            continue;     
          }

        //One entry may - but shouldn't - contain multiple ticket ids
        foreach ($this->getTicketIds($entry) as $ticketId) {

          //Get the case title.
          $this->debug("/");
          $title = $this->bugtracker->getTitle($ticketId);
          $this->debug("\\");

          if ($title) {
            preg_match('/'.$ticketId.'(?:\[(.*?)\])?/i', $entry->get('notes'), $matches);
            if (isset($matches[1])) {

              // No bugs found here yet, but I suspect that we should encode the matches array. NOTE: Added CDATA later on...
              if ($matches[1] != $title) {
                //Entry note includes ticket title it does not match current title
                //so update it

                // look for double brackets - in there are the original ticket name contains brakcets like this, then we have a problem as the regex will break: "[Bracket] - Antal af noder TEST"
                if(strpos($title,"[") !== false || strpos($title,"]") !== false) {
                  // hmm, brackets detected, initiate evasive maneuvre :-)
                  $output->writeln(sprintf('WARNING (bad practice) ticket contains [brackets] in title %s: %s', $ticketId, $title));
                  $entry->set('notes', $ticketId.'['.$title.'] (BugYield removed comments due to [brackets] in the ticket title)'); // we have to drop comments (if any) and just insert the ticket title, as we cannot differentiate what's title and whats comment.
                }
                else
                {
                  $entry->set('notes', preg_replace('/'.$ticketId.'(\[.*?\])/i', $ticketId.'['.$title.']', $entry->get('notes')));
                }

                $update = true;
              }
            } else {
              //Entry note does not include ticket title so add it
              $entry->set('notes', preg_replace('/'.$ticketId.'/i', strtoupper($ticketId).'['.$title.']', $entry->get('notes')));

              $update = true;
            }
          }
          else
          {
            $output->writeln(sprintf('WARNING: Title for TicketID %s could not be found. Probably wrong ID', $ticketId));
          }          
        }

        if ($update) {

          // adding CDATA tags around the notes - or Harvest will fail on chars as < > & -- Harvest removes < and > in the website editor btw
          $entry->set('notes', $entry->get('notes'));

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
    } catch (\Exception $e) {
      $output->writeln('Error communicating with bug tracker: '. $e->getMessage());
    }

    $this->debug("\n");
    $output->writeln("TitleSync completed");
  }
}
