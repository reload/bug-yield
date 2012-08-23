<?php

namespace BugYield\Command;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class MaxHoursCheck extends BugYieldCommand {

  protected function configure() {
    $this
      ->setName('bugyield:maxhourscheck')
      ->setAliases(array('mhc', 'maxhours'))
      ->setDescription('Check whether a time registration contains more than a certain number of hours');
    parent::configure();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    //store harvestentries and ticket id's for later comparison and double checking
    $checkHarvestEntries = array();

    $ignoreLocked     = FALSE;
    $fromDate         = date("Ymd", time() - (86400 * $this->getTimeTrackerDaysBack()));
    $toDate           = date("Ymd");
    $uniqueTicketIds  = array();
    $notifyOnError    = self::getEmailNotifyOnError(); // email to notify on error, typically a PM

    $this->log(sprintf("Collecting Harvest entries between %s to %s", $fromDate, $toDate));
    if ($ignoreLocked) {
      $output->log("-- Ignoring entries already billed or otherwise closed.");
    }

    $ticketEntries = $this->getTicketEntries($fromDate, $toDate, $ignoreLocked);

    $this->log(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
    if (sizeof($ticketEntries) == 0) {
      //We have no entries containing ticket ids so bail
      return;
    }

    try {
      foreach ($ticketEntries as $entry) {
        $this->log('.', LOG_DEBUG, FALSE);
                          
        // check for active timers - let's not update bug tracker with
        // timers still running...
        if ($entry->isTimerActive()) {
          // we have an active timer, bounce off!
          $this->log('', LOG_DEBUG);
          $this->log(sprintf('SKIPPED (active timer) entry #%d: %s', $entry->getId(), $entry->getText()));
            continue;
        }                       
                          
        // One entry may - but shouldn't - contain multiple ticket ids
        $ticketIds = $this->bugtracker->getTicketIds($entry->getText());
        $task      = $this->timetracker->getTask($entry->getTaskId());             
        $user      = $this->timetracker->getUser($entry->getUserId());
        $project   = $this->timetracker->getProject($entry->getProjectId());

        // report an error if you have one single ticket entry with more than 
        // a configurable number of hours straight. That's very odd.
        // TODO: Move this to separate command
        if ($this->getMaxEntryHours() &&
            $entry->getHoursSpent() > $this->getMaxEntryHours()) {
          $this->log(sprintf('WARNING: More than %s hours registrered on %s: %s (%s hours). Email sent to user.', $this->getMaxEntryHours(), $entry->getId(), $entry->getText(), $entry->getHoursSpent()));

          $user = $this->timetracker->getUser($entry->getUserId());
          $to = '"' . $user->getName() . '" <' . $user->getEmail() . '>';
          $subject = sprintf('BugYield warning: %s hours registered on %s. Really?', $entry->getHoursSpent(), $entry->getText());
          $body = array();
          $body[] = sprintf('The following Harvest entry seems invalid due to more than %s registered hours on one task:', $this->getMaxEntryHours());
          $body[] = '';
          $body[] = (string) $entry;
          $body[] = '';
          $body[] = 'ACTION: Please review the time entry.';
          $body[] = 'If it is actually valid, then you have to split it up in separate entries below 8 hours in order to avoid this message.';
          $body[] = '';
          $body[] = 'NOTICE: If you have no clue what you should do to fix your time registration';
          $body[] = 'in Harvest please ask your friendly BugYield administrator: ' . self::getBugyieldEmailFrom();
          $headers = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
          
          // add CC if defined in the config
          if(!empty($notifyOnError)) {
            $headers .= 'Cc: ' . $notifyOnError . "\r\n";
          }

          if(!mail($to, $subject, implode("\n",$body), $headers))
          {
            $this->log('  > ERROR: Could not send email to: '. $to);
          }
        }
      }
    } catch (\Exception $e) {
      $this->log('Error communicating with bugtracker: '. $e->getMessage());
    }

    $this->log("MaxHoursCheck completed");
  }
}
