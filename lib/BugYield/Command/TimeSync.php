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

    $ignore_locked  = false;
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

    //Update bug tracker with time registrations
    try {
      foreach ($ticketEntries as $entry) {
                          
        // check for active timers - let's not update bug tracker with
        // timers still running...
        if(strlen($entry->get("timer-started-at")) != 0)
          {
            // we have an active timer, bounce off!
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
        
        foreach($ticketIds as $id) {
	  // saveTimelogEntry() will handle whether to add or update
	  // entries.
	  try {
	    $this->bugtracker->saveTimelogEntry($id, $worklog);	    
	  } 
	  catch (\Exception $e) {
	    $to = '"' . $this->getUserNameById($entry->get('user-id')) . '" <' . $this->getUserEmailById($entry->get('user-id')) . '>';
	    $subject = $id . ': time sync exception';
	    $body = array();
	    $body[] = 'Trying to sync Harvest entry:';
	    $body[] = print_r($worklog, TRUE);
	    $body[] = 'Failed with exeception:';
	    $body[] = $e->getMessage();
	    $headers = array();
	    $headers[] = 'From: "BugYield" <' . self::getBugyieldEmailFrom() . '>';
	    mail($to, $subject, implode("\n", $body), implode("\r\n", $headers));
	  }
        }
      }

      /* // @todo error checking */
      /* $possibleErrors = array(); */

      /* foreach($checkFogBugzEntries as $fbId => $harvestEntriesData) { */
      /*   foreach($harvestEntriesData as $hEntryData) { */
      /*     $hEntryId = $hEntryData[1]; */
      /*     if(!isset($checkHarvestEntries[$hEntryId]) || !in_array($fbId,$checkHarvestEntries[$hEntryId])) { */
      /*       $possibleErrors[] = array($fbId => $hEntryData); */
      /*     } */
      /*   } */
      /* } */

      /* // if we have possible error, then look up the Harvest Entries, as they might just be old and therefore out of our current scope. */
      /* // Or the entry could have been deleted or edited out, thereby making FB data out-of-sync. */
      /* if(!empty($possibleErrors)) { */
      /*   $realErrors = array(); */

      /*   foreach($possibleErrors as $data) { */
      /*     foreach($data as $fbId => $hEntryData) { */

      /*       $errorData      = array(); */
      /*       $hUserId        = false; */
      /*       $hUserEmail     = self::getBugyieldEmailFallback(); */

      /*       $hEntryId       = $hEntryData[1]; */
      /*       $hEntryUser     = trim(html_entity_decode($hEntryData[4], ENT_COMPAT, "UTF-8")); */
      /*       $Harvest_User   = self::getHarvestUserByFullName($hEntryUser); */

      /*       if($Harvest_User) { */
      /*         $hUserId    = $Harvest_User->get("id"); */
      /*         $hUserEmail = $Harvest_User->get("email"); */

      /*         //$output->writeln(sprintf('DEBUG: User %s results in uid %d and email %s', $hEntryUser, $hUserId, $hUserEmail)); */
      /*       } */
      /*       else { */
      /*         $output->writeln(sprintf('WARNING: Could not find email for this user: %s', $hEntryUser)); */
      /*       } */

      /*       // basis data */
      /*       $errorData["bugID"]   = $fbId; */
      /*       $errorData["name"]    = $hEntryUser; */
      /*       $errorData["userid"]  = $hUserId; */
      /*       $errorData["email"]   = $hUserEmail; */
      /*       $errorData["date"]    = $hEntryData[5]; */
      /*       $errorData["bugNote"] = html_entity_decode(strip_tags($hEntryData[0])); */
      /*       $errorData["entryid"] = $hEntryId; */

      /*       // fetch entry from Harvest */
      /*       if($entry = self::getEntryById($hEntryId,$hUserId)) { */
      /*         // look for the ID */
      /*         $ticketIds = self::getTickedIds($entry); */
      /*         if(!in_array($fbId,$ticketIds)) { */
      /*           // error found! The time entry still exist, but there is no reference to this bug anylonger */

      /*           // check that the FB user matches the entry user. If not, load the proper user */
      /*           if($hUserId != $entry->get("user-id")) { */
      /*             // hmm, this is unusual... */
      /*             $output->writeln(sprintf('WARNING: We have an errornous reference from BugID #%d to timeentry #%d where the users do not match: %s', $fbId, $entry->get("id"), var_export($hEntryData,true))); */
      /*           } */

      /*           // Add error 1 reason */
      /*           $errorData["entryNote"] = $entry->get("notes"); */
      /*           $errorData["reason"]  = sprintf("Error 1: The time entry (#%d) still exist in Harvest, but there is no reference to bug #%d from the entry.",$errorData["entryid"],$fbId); */
      /*           $realErrors[] = $errorData; */
      /*         } */
      /*         else { */
      /*           continue; */
      /*         } */
      /*       } */
      /*       else { */
      /*         // error found! No entry found in Harvest, FogBugz is referring to a timeentry that does not exist anylonger. */
      /*         // add error 2 reason */
      /*         $errorData["entryNote"] = "ENTRY DELETED"; */
      /*         $errorData["reason"]  = sprintf("Error 2: No entry found in Harvest, FogBugz bug #%d is referring to a timeentry (#%d) that does not exist anylonger.",$fbId,$errorData["entryid"]);                       */
      /*         $realErrors[] = $errorData; */
      /*       } */
      /*     } */
      /*   } */

      /*   if(!empty($realErrors)) { */
      /*     $output->writeln(sprintf('ERRORs found: We have %d erroneous references from FogBugz to Harvest. Users will be notified by email, stand by...', count($realErrors))); */

      /*     foreach($realErrors as $errorData) { */

      /*       // data for the mail */
      /*       $unixdate         = strtotime($errorData["date"]); */
      /*       $year             = date("Y",$unixdate); // YYYY */
      /*       $dayno            = date("z",$unixdate)+1; // Day of the year, eg. 203 */
      /*       $harvestEntryUrl  = sprintf(self::getHarvestURL() . "daily/%d/%d/%d#timer_link_%d", $errorData["userid"], $dayno, $year, $errorData["entryid"]); */

      /*       // build the mail to be sent */
      /*       $subject  = sprintf("BugYield Synchronisation error found in #%d registered %s by %s", $errorData["bugID"], $errorData["date"], $errorData["name"]); */
      /*       $body     = sprintf("Hi %s,\nBugYield has found some inconsistencies between Harvest and data registrered on bug #%d. Please review this error:\n\n%s\n", $errorData["name"],$errorData["bugID"],$errorData["reason"]); */
      /*       $body     .= sprintf("\nLink to FogBugz: %s", self::getFogBugzURL() . "/default.asp?".$errorData["bugID"]); */
      /*       $body     .= sprintf("\nLink to Harvest: %s", $harvestEntryUrl); */
      /*       $body     .= "\n\nData from Harvest:\n" . $errorData["entryNote"]; */
      /*       $body     .= "\nData from FogBugz:\n" . $errorData["bugNote"]; */
      /*       $body     .= "\n\nIMPORTANT: In order to fix this, you must manually edit the entries, e.g. by editing/removing the logdata from FogBugz and subtract the time added."; */
      /*       $headers  = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion();        */


      /*       $output->writeln(sprintf("  > Sync error found in #%d: %s - Reason: %s", $errorData["bugID"], $errorData["bugNote"], $errorData["reason"])); */


      /*       if(!mail($errorData["email"], $subject, $body, $headers)) */
      /*         { */
      /*           $output->writeln(sprintf('  > Could not send email to %s', $errorData["email"])); */
      /*           // send to admin instead */
      /*           mail(self::getBugyieldEmailFallback(), "FALLBACK: " . $subject, $body, $headers); */
      /*         } */
      /*       else */
      /*         { */
      /*           $output->writeln(sprintf('  > Email sent to %s', $errorData["email"])); */
      /*         } */
      /*     } */
      /*   } */
      /* } */


    } catch (FogBugz_Exception $e) {
      $output->writeln('Error communicating with FogBugz: '. $e->getMessage());
    }

    $output->writeln("TimeSync completed");
  }
}
