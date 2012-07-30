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
      ->setDescription('Sync time registration from Harvest to bug tracker');
    parent::configure();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->loadConfig($input);
    $this->getBugTrackerApi($input);

    //Setup Harvest API access
    $harvest = $this->getHarvestApi();

    //store harvestentries and ticket id's for later comparison and double checking
    $checkHarvestEntries = array();

    $output->writeln('TimeSync executed: ' . date('Ymd H:i:s'));
    $output->writeln(sprintf('Bugtracker is %s (%s)', $this->bugtracker->getName(), $this->getBugtrackerURL()));
    $output->writeln('Verifying projects in Harvest');

    $projects = $this->getProjects($this->getProjectIds($input));
    if (sizeof($projects) == 0) {
      //We have no projects to work with so bail
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

    $ignore_locked    = false;
    $from_date        = date("Ymd",time()-(86400*$this->getHarvestDaysBack()));
    $to_date          = date("Ymd");
    $uniqueTicketIds  = array();
    $notifyOnError    = self::getEmailNotifyOnError(); // email to notify on error, typically a PM

    $output->writeln(sprintf("Collecting Harvest entries between %s to %s",$from_date,$to_date));
    if($ignore_locked) $output->writeln("-- Ignoring entries already billed or otherwise closed.");

    $ticketEntries = $this->getTicketEntries($projects, $ignore_locked, $from_date, $to_date);

    $output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
    if (sizeof($ticketEntries) == 0) {
      //We have no entries containing ticket ids so bail
      return;
    }

    //Update bug tracker with time registrations
    try {
      foreach ($ticketEntries as $entry) {
        $this->debug(".");
                          
        // check for active timers - let's not update bug tracker with
        // timers still running...
        if(strlen($entry->get("timer-started-at")) != 0)
          {
            // we have an active timer, bounce off!
            $this->debug("\n");
            $output->writeln(sprintf('SKIPPED (active timer) entry #%d: %s', $entry->get('id'), $entry->get('notes')));
            continue;
          }                       
                          
        //One entry may - but shouldn't - contain multiple ticket ids
        $ticketIds = $this->getTicketIds($entry);

        // store the ticketinfo
        if(!empty($ticketIds)) {
          $checkHarvestEntries[$entry->get('id')] = $ticketIds;
        }

        //Determine task
        $response = $harvest->getTask($entry->get('task-id'));
        $taskName = ($response->isSuccess()) ? $response->get('data')->get('name') : 'Unknown';
                                
        $harvestUserName      = $this->getUserNameById($entry->get("user-id"));
        $harvestProjectName   = self::getProjectNameById($projects,$entry->get("project-id"));
        $harvestTimestamp     = $entry->get("spent-at");

        $entryText = sprintf('Entry #%d [%s/%s]: "%s" %sby %s @ %s in "%s"', $entry->get('id'), $entry->get('hours'), $taskName, $entry->get('notes'), "\r\n", $harvestUserName, $harvestTimestamp, $harvestProjectName);

        //In case there are several ids in an entry then distribute the the time spent evenly
        $hoursPerTicket = round(floatval($entry->get('hours')) / sizeof($ticketIds), 2);

        $worklog = new \stdClass;
        $worklog->harvestId = $entry->get('id');
        $worklog->user      = $harvestUserName;
        $worklog->hours     = $hoursPerTicket;
        $worklog->spentAt   = $harvestTimestamp;
        $worklog->project   = $harvestProjectName;
        $worklog->taskName  = $taskName;
        $worklog->notes     = $entry->get('notes');

        // report an error if you have one single ticket entry with more than 8 hours straight. That's very odd.
        if($hoursPerTicket > 8) {

          $output->writeln(sprintf('WARNING: More than 8 hours registrered on %s: %s (%s hours). Email sent to user.',$worklog->harvestId, $worklog->notes, $worklog->hours));

          $to = '"' . $this->getUserNameById($entry->get('user-id')) . '" <' . $this->getUserEmailById($entry->get('user-id')) . '>';
          $subject = sprintf('BugYield warning: %s hours registered on %s. Really?', $worklog->hours, $worklog->notes);
          $body = array();
          $body[] = 'The following Harvest entry seems invalid due to more than 8 registered hours on one task:';
          $body[] = '';
          $body[] = print_r($worklog, TRUE);
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
            $output->writeln('  > ERROR: Could not send email to: '. $to);
          }
        }
        
        foreach($ticketIds as $id) {
          // entries.
          try {
            // saveTimelogEntry() will handle whether to add or update
            $this->debug("/");
            $updated = $this->bugtracker->saveTimelogEntry($id, $worklog);
            $this->debug("\\");

            if($updated) {
              $output->writeln(sprintf('Updated %s: %s in %s', $id, $worklog->notes, $this->bugtracker->getName()));
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
            $to = '"' . $this->getUserNameById($entry->get('user-id')) . '" <' . $this->getUserEmailById($entry->get('user-id')) . '>';
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
      $output->writeln('EXTENDED TEST has been enabled, all referenced tickets will be checked even if they were not updated. Set extended_test = false to disable this.');
    }
    $output->writeln(sprintf('Starting error checking: %d tickets will be checked...', sizeof($uniqueTicketIds)));

    $checkBugtrackerEntries = array();
    $possibleErrors = array();
    $worklog = null;
    $bugtrackerName   = $this->bugtracker->getName();

    foreach($uniqueTicketIds as $id) {
      $this->debug(".");
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

          $hEntryUser     = trim(html_entity_decode($worklog->user, ENT_COMPAT, "UTF-8"));
          $Harvest_User   = self::getHarvestUserByFullName($hEntryUser);

          if($Harvest_User) {
            $hUserId    = $Harvest_User->get("id");
            $hUserEmail = $Harvest_User->get("email");

            //$output->writeln(sprintf('DEBUG: User %s results in uid %d and email %s', $hEntryUser, $hUserId, $hUserEmail));
          }
          else {
            $output->writeln(sprintf('WARNING: Could not find email for this user: "%s"', $hEntryUser));
            $output->writeln('-------- As the user cannot be found, the following checks will fail as well, so this entry will be skipped. Check user names for spelling errors, check ticket name for wierd characters breaking the regex etc.');
            continue;
          }

          // basis data
          $errorData["bugID"]     = $fbId;
          $errorData["name"]      = $hEntryUser;
          $errorData["userid"]    = $hUserId;
          $errorData["email"]     = $hUserEmail;
          $errorData["date"]      = $worklog->spentAt;
          $errorData["bugNote"]   = html_entity_decode(strip_tags($worklog->notes));
          $errorData["entryid"]   = $worklog->harvestId;
          $errorData["remoteId"]  = $worklog->remoteId;

          // fetch entry from Harvest
          if($entry = self::getEntryById($worklog->harvestId,$hUserId)) {
            // look for the ID
            $ticketIds = self::getTicketIds($entry);
            if(!in_array($fbId,$ticketIds)) {
              // error found! The time entry still exist, but there is no reference to this bug anylonger

              // check that the bugtracker user matches the entry user. If not, load the proper user
              if($hUserId != $entry->get("user-id")) {
                // hmm, this is unusual...
                $output->writeln(sprintf('WARNING: We have an errornous reference from BugID %s to timeentry %s where the users do not match: %s', $fbId, $entry->get("id"), var_export($hEntryData,true)));
              }

              // Add error 1 reason
              $errorData["entryNote"] = $entry->get("notes");
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
            $errorData["reason"]  = sprintf("Error 2: No entry found in Harvest, %s ticket %s is referring to a timeentry (%s) that does not exist anylonger.",$bugtrackerName,$fbId,$errorData["entryid"]);		        
            $realErrors[] = $errorData;
          }
        }
      }

      if(!empty($realErrors)) {
        $output->writeln(sprintf('ERRORs found: We have %s erroneous references from %s to Harvest. Users will be notified by email, stand by...', count($realErrors), $bugtrackerName));

        foreach($realErrors as $errorData) {

          // data for the mail
          $unixdate         = strtotime($errorData["date"]);
          $year             = date("Y",$unixdate); // YYYY
          $dayno            = date("z",$unixdate)+1; // Day of the year, eg. 203
          $harvestEntryUrl  = sprintf(self::getHarvestURL() . "daily/%d/%d/%d#timer_link_%d", $errorData["userid"], $dayno, $year, $errorData["entryid"]);

          // build the mail to be sent
          $subject  = sprintf("BugYield Synchronisation error found in %s registered %s by %s", $errorData["bugID"], $errorData["date"], $errorData["name"]);
          $body     = sprintf("Hi %s,\nBugYield has found some inconsistencies between Harvest and data registrered on bug %s. Please review this error:\n\n%s\n", $errorData["name"],$errorData["bugID"],$errorData["reason"]);
          $body     .= sprintf("\nLink to %s: %s", $bugtrackerName, self::getBugtrackerTicketURL($this->bugtracker->sanitizeTicketId($errorData["bugID"]),$errorData["remoteId"]));
          $body     .= sprintf("\nLink to Harvest: %s", $harvestEntryUrl);
          $body     .= sprintf("\n\nCurrent data from Harvest entry:\n  %s", $errorData["entryNote"]);
          $body     .= sprintf("\n\nOutdated Harvest data logged in %s:\n  %s",$bugtrackerName, $errorData["bugNote"]);
          $body     .= sprintf("\n\nIMPORTANT: In order to fix this, you must manually edit the entries, e.g. by editing/removing the logdata from %s and subtract the time added.",$bugtrackerName);
          $headers  = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
          // add CC if defined in the config
          if(!empty($notifyOnError)) {
            $headers .= 'Cc: ' . $notifyOnError . "\r\n";
          }

          $output->writeln(sprintf("  > Sync error found in %s: %s - Reason: %s", $errorData["bugID"], $errorData["bugNote"], $errorData["reason"]));

          if(!mail($errorData["email"], $subject, $body, $headers))
          {
            $output->writeln(sprintf('  > Could not send email to %s', $errorData["email"]));
            // send to admin instead
            mail(self::getBugyieldEmailFallback(), "FALLBACK: " . $subject, $body, $headers);
          }
          else
          {
            $output->writeln(sprintf('  > Email sent to %s', $errorData["email"]));
          }
        }
      }
    }

    } catch (\Exception $e) {
      $output->writeln('Error communicating with bugtracker: '. $e->getMessage());
    }

    $output->writeln("TimeSync completed");
  }
}
