<?php

namespace BugYield\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TimeSync extends BugYieldCommand {

  protected function configure() {
    $this
      ->setName('bugyield:timesync')
      ->setAliases(array('tim', 'timesync'))
      ->setDescription('Sync time registration from Harvest to bug tracker');
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
      $this->log("-- Ignoring entries already billed or otherwise closed.");
    }

    $ticketEntries = $this->getTicketEntries($fromDate, $toDate, $ignoreLocked);

    $this->log(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
    if (sizeof($ticketEntries) == 0) {
      //We have no entries containing ticket ids so bail
      return;
    }

    //Update bug tracker with time registrations
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
        $ticketIds = $this->bugtracker->extractTicketIds($entry->getText());

        // store the ticketinfo
        if(!empty($ticketIds)) {
          $checkHarvestEntries[$entry->getId()] = $ticketIds;
        }

        $task      = $this->timetracker->getTask($entry->getTaskId());             
        $user      = $this->timetracker->getUser($entry->getUserId());
        $project   = $this->timetracker->getProject($entry->getProjectId());

        $entryText = sprintf('Entry #%d [%s/%s]: "%s" %sby %s @ %s in "%s"', $entry->getId(), $entry->getHoursSpent(), $task->getName(), $entry->getText(), "\r\n", $user->getName(), $entry->getTimestamp(), $project->getName());

        //In case there are several ids in an entry then distribute the the time spent evenly
        $hoursPerTicket = round(floatval($entry->getHoursSpent()) / sizeof($ticketIds), 2);

        $worklog = new \stdClass;
        $worklog->harvestId = $entry->getId();
        $worklog->user      = $user->getName();
        $worklog->hours     = $hoursPerTicket;
        $worklog->spentAt   = $entry->getTimestamp();
        $worklog->project   = $project->getName();
        $worklog->taskName  = $task->getName();
        $worklog->notes     = $entry->getText();
        
        foreach($ticketIds as $id) {
          // entries.
          try {
            // saveTimelogEntry() will handle whether to add or update
            $this->log('/', LOG_DEBUG, FALSE);
            $updated = $this->bugtracker->saveTimelogEntry($id, $worklog);
            $this->log("\\", LOG_DEBUG, FALSE);

            if($updated) {
              $this->log(sprintf('Updated %s: %s in %s', $id, $worklog->notes, $this->bugtracker->getName()));
            }

            // save entries for the error checking below.
            // This only runs/checks if a ticket has been updated.
            if($updated || $this->doExtendedTest()) {
              if(empty($uniqueTicketIds) || !array_key_exists($id,$uniqueTicketIds)) {
                $uniqueTicketIds[$id] = $id;
              }
            }
          }
          catch (\Exception $e) {
            $user = $this->timetracker->getUser($entry->get('user-id'));
            $to = '"' . $user->getName() . '" <' . $user->getEmail() . '>';
            $subject = $id . ': time sync exception';
            $body = array();
            $body[] = 'Trying to sync Harvest entry:';
            $body[] = '';
            $body[] = print_r($worklog, TRUE);
            $body[] = 'Failed with exception:';
            $body[] = $e->getMessage();
            $body[] = '';
            $body[] = 'NOTICE: If you have no clue what you should do to fix your time registration';
            $body[] = 'in Harvest please ask your friendly BugYield administrator: ' . self::getBugyieldEmailFrom();
            $headers = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
            // add CC if defined in the config
            if(!empty($notifyOnError)) {
              $headers .= 'Cc: ' . $notifyOnError . "\r\n";
            }
            mail($to, $subject, implode("\n", $body), $headers);
          }
        }
      }

    // ERROR CHECKING BELOW
    // This code will look for irregularities in the logged data, namely if the bugtracker is out-of-sync with Harvest
    // Currently this runs whenever a ticket in the bugtracker has been referenced in Harvest - then all the logged entries are checked.

    if($this->doExtendedTest()) {
      $this->log('EXTENDED TEST has been enabled, all referenced tickets will be checked even if they were not updated. Set extended_test = false to disable this.');
    }
    $this->log(sprintf('Starting error checking: %d tickets will be checked...', sizeof($uniqueTicketIds)));

    $checkBugtrackerEntries = array();
    $possibleErrors = array();
    $worklog = null;

    foreach($uniqueTicketIds as $id) {
      $this->log('.', LOG_DEBUG, FALSE);
      $checkBugtrackerEntries[$id] = $this->bugtracker->getTimelogEntries($id);
    }

    foreach($checkBugtrackerEntries as $fbId => $harvestEntriesData) {
      foreach($harvestEntriesData as $worklog) {
        if(!isset($checkBugtrackerEntries[$worklog->harvestId]) || !in_array($fbId,$checkHarvestEntries[$worklog->harvestId])) {
          $possibleErrors[] = array($fbId => $worklog);
        }
      }
    }

    // if we have possible error, then look up the Harvest Entries, as they might just be old and therefore out of our current scope.
    // Or the entry could have been deleted or edited out, thereby making Bugtracker data out-of-sync.
    if(!empty($possibleErrors)) {
      $realErrors = array();

      foreach($possibleErrors as $data) {
        foreach($data as $fbId => $worklog) {

          $errorData      = array();
          $hUserId        = false;
          $hUserEmail     = self::getBugyieldEmailFallback();

          $userName       = trim(html_entity_decode($worklog->user, ENT_COMPAT, "UTF-8"));
          $user           = $this->timetracker->getUserByFullName($userName);

          if (!$user) {
            $this->log(sprintf('WARNING: Could not find email for this user: "%s"', $userName));
            $this->log('-------- As the user cannot be found, the following checks will fail as well, so this entry will be skipped. Check user names for spelling errors, check ticket name for wierd characters breaking the regex etc.');
            continue;
          }

          // basis data
          $errorData["bugID"]     = $fbId;
          $errorData["name"]      = $userName;
          $errorData["userid"]    = $user->getId();
          $errorData["email"]     = $user->getEmail();
          $errorData["date"]      = $worklog->spentAt;
          $errorData["bugNote"]   = html_entity_decode(strip_tags($worklog->notes));
          $errorData["entryid"]   = $worklog->harvestId;
          $errorData["remoteId"]  = $worklog->remoteId;

          // fetch entry from Harvest
          if ($entry = $this->timetracker->getEntry($worklog->harvestId, $user->getId())) {
            // look for the ID
            $ticketIds = $this->getTicketIds($entry);
            if(!in_array($fbId, $ticketIds)) {
              // error found! The time entry still exist, but there is no reference to this bug anylonger

              // check that the bugtracker user matches the entry user. If not, load the proper user
              if($hUserId != $entry->get("user-id")) {
                // hmm, this is unusual...
                $this->log(sprintf('WARNING: We have an errornous reference from BugID %s to timeentry %s where the users do not match: %s', $fbId, $entry->getId(), var_export($hEntryData,true)));
              }

              // Add error 1 reason
              $errorData["entryNote"] = $entry->getText();
              $errorData["reason"]  = sprintf("Error 1: The time entry (%s) still exist in Harvest, but there is no reference to ticket %s from the entry. This could mean that you have changed or removed the ticketId in this particular Harvest time entry.",$errorData["entryid"],$fbId);
              $realErrors[] = $errorData;
            }
            else {
              continue;
            }
          }
          else {
            // error found! No entry found in Harvest, the Bugtracker is referring to a timeentry that does not exist anylonger.
            // add error 2 reason
            $errorData["entryNote"] = "ENTRY DELETED";
            $realErrors[] = $errorData;
          }
        }
      }

      if(!empty($realErrors)) {

        foreach($realErrors as $errorData) {

          // data for the mail
          $unixdate         = strtotime($errorData["date"]);
          $year             = date("Y",$unixdate); // YYYY
          $dayno            = date("z",$unixdate)+1; // Day of the year, eg. 203
          $time  = sprintf($this->timetracker->getUrl() . "daily/%d/%d/%d#timer_link_%d", $errorData["userid"], $dayno, $year, $errorData["entryid"]);

          // build the mail to be sent
          $subject  = sprintf("BugYield Synchronisation error found in %s registered %s by %s", $errorData["bugID"], $errorData["date"], $errorData["name"]);
          $body     = sprintf("Hi %s,\nBugYield has found some inconsistencies between Harvest and data registrered on bug %s. Please review this error:\n\n%s\n", $errorData["name"],$errorData["bugID"],$errorData["reason"]);
          $body     .= sprintf("\nLink to %s: %s", $this->bugtracker->getName(), $this->bugtracker->getTicketUrl($this->bugtracker->sanitizeTicketId($errorData["bugID"]),$errorData["remoteId"]));
          $body     .= sprintf("\nLink to %s: %s", $this->timetracker->getName(), $this->timetracker->getEntryUrl($entry));
          $body     .= sprintf("\n\nCurrent data from Harvest entry:\n  %s", $errorData["entryNote"]);
          $headers  = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
          // add CC if defined in the config
          if(!empty($notifyOnError)) {
            $headers .= 'Cc: ' . $notifyOnError . "\r\n";
          }

          $this->log(sprintf("  > Sync error found in %s: %s - Reason: %s", $errorData["bugID"], $errorData["bugNote"], $errorData["reason"]));

          if(!mail($errorData["email"], $subject, $body, $headers))
          {
            $this->log(sprintf('  > Could not send email to %s', $errorData["email"]));
            // send to admin instead
            mail(self::getBugyieldEmailFallback(), "FALLBACK: " . $subject, $body, $headers);
          }
          else
          {
            $this->log(sprintf('  > Email sent to %s', $errorData["email"]));
          }
        }
      }
    }

    } catch (\Exception $e) {
      $this->log('Error communicating with bugtracker: '. $e->getMessage());
    }

    $this->log(' ', LOG_DEBUG);
    $this->log("TimeSync completed");
  }
}
