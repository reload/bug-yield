<?php

namespace BugYield\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TitleSync extends BugYieldCommand {

  protected function configure() {
    $this
      ->setName('bugyield:titlesync')
      ->setAliases(array('tit', 'titlesync'))
      ->setDescription('Sync ticket titles from bug tracker to Harvest');
    parent::configure();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $ignoreLocked   = TRUE;
    $fromDate       = date("Ymd", time() - (86400 * $this->getTimeTrackerDaysBack()));
    $toDate         = date("Ymd");
    $ticketEntries = $this->getTicketEntries($fromDate, $toDate, $ignoreLocked);

    $this->log(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
    if (sizeof($ticketEntries) == 0) {
      //We have no entries containing ticket ids so bail
      return;
    }

    //Update Harvest entries with bug tracker ticket titles in the format [ticket-id]([ticket-title])
    try {
      foreach ($ticketEntries as $entry) {
        $update = false;
        $this->log('.', LOG_DEBUG, FALSE);

        // check for active timers - if we update the entry, then the timer will be disrupted, and odd things start to happen :-/
        if ($entry->isTimerActive()) {
          // we have an active timer, bounce off!
          $this->log(' ', LOG_DEBUG);
          $this->log(sprintf('SKIPPED (active timer) entry %s: %s', $entry->getId(), $entry->getText()));
          continue;     
        }

        //One entry may - but shouldn't - contain multiple ticket ids
        foreach ($this->bugtracker->extractTicketIds($entry->getText()) as $ticketId) {
          //Get the case title.
          $this->log("/", LOG_DEBUG, FALSE);
          // TODO: Consider making a base class for tickets
          $ticket = $this->bugtracker->getTicket($ticketId);
          $this->log("\\", LOG_DEBUG, FALSE);

          if ($ticket->getTitle()) {
            preg_match('/' .$ticketId. '(?:\[(.*?)\])?/i', $entry->getText(), $matches);
            if (isset($matches[1])) {

              // No bugs found here yet, but I suspect that we should encode the matches array. NOTE: Added CDATA later on...
              if ($matches[1] != $ticket->getTitle()) {
                //Entry note includes ticket title but it does not match current title
                //so update it

                // look for double brackets - in there are the original ticket name contains brakcets like this, then we have a problem as the regex will break: "[Bracket] - Antal af noder TEST"
                if (strpos($ticket->getTitle(), "[") !== false || strpos($ticket->getTitle(), "]") !== false) {
                  // hmm, brackets detected, initiate evasive maneuvre :-)
                  $this->log(sprintf('WARNING (bad practice) ticket contains [brackets] in title %s: %s', $ticketId, $title));
                  $entry->setText($ticketId . '[' . $ticket->getTitle() . '] (BugYield removed comments due to [brackets] in the ticket title)'); // we have to drop comments (if any) and just insert the ticket title, as we cannot differentiate what's title and whats comment.
                } else {
                  $entry->setText(preg_replace('/'.$ticketId.'(\[.*?\])/i', $ticketId. '[' . $ticket->getTitle() .']', $entry->getText()));
                }
              }
            } else {
              //Entry note does not include ticket title so add it
              $entry->setText(preg_replace('/'.$ticketId.'/i', strtoupper($ticketId). '[' . $ticket->getTitle() . ']', $entry->getText()));
            }
          }
        }

        if ($entry->save()) {
          $this->log(sprintf('Updated entry %s: %s', $entry->getId(), $entry->getText()));
        } else {
          $errormsg[] = sprintf('FAILED to update entry %s: %s (EntryDate: %s)', $entry->getId(), $entry->getText(), $entry->getTimestamp());
          $this->log($errormsg);
          error_log(date("d-m-Y H:i:s") . " | " . $errormsg . "\n", 3, "error.log");
        }
      }
    } catch (Exception $e) {
      $this->log('Error communicating with bug tracker: '. $e->getMessage());
    }

    $this->log(' ', LOG_DEBUG);
    $this->log("TitleSync completed");
  }
}
