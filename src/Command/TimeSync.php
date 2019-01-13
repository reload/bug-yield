<?php

namespace BugYield\Command;

use BugYield\Config;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

class TimeSync extends BugYieldCommand
{

    /**
     * Invoke TimeSync command.
     */
    public function __invoke(InputInterface $input, OutputInterface $output)
    {
        $this->setDebug($input);

        //store harvestentries and ticket id's for later comparison and double checking
        $checkHarvestEntries = array();

        $output->writeln('TimeSync executed: ' . date('Ymd H:i:s'));
        $output->writeln(sprintf('Bugtracker is %s (%s)', $this->getBugtracker()->getName(), $this->getBugtrackerURL()));
        $output->writeln('Verifying projects in Harvest');

        $projects = $this->getTimetracker()->getProjects($this->getProjectIds($input));
        if (sizeof($projects) == 0) {
            //We have no projects to work with so bail
            if (!isset($input) || !is_string($input)) {
                $input = "ARGUMENT IS NULL";
            }
            $output->writeln(sprintf('Could not find any projects matching: %s', $input));
            return;
        }

        foreach ($projects as $Harvest_Project) {
            $archivedText = "";
            if ($Harvest_Project->get("active") == "false") {
                $archivedText = sprintf(
                    "ARCHIVED (Latest activity: %s)",
                    $Harvest_Project->get("hint-latest-record-at")
                );
            }
            $output->writeln(sprintf(
                'Working with project: %s %s %s',
                self::mbStrPad($Harvest_Project->get("name"), 40, " "),
                self::mbStrPad($Harvest_Project->get("code"), 18, " "),
                $archivedText
            ));
        }

        $ignore_locked    = false;
        $from_date        = date("Ymd", time()-(86400*$this->getHarvestDaysBack()));
        $to_date          = date("Ymd");
        $uniqueTicketIds  = array();
        $notifyOnError    = self::getEmailNotifyOnError(); // email to notify on error, typically a PM

        $output->writeln(sprintf("Collecting Harvest entries between %s to %s", $from_date, $to_date));
        if ($ignore_locked) {
            $output->writeln("-- Ignoring entries already billed or otherwise closed.");
        }

        $ticketEntries = $this->getTicketEntries($projects, $ignore_locked, $from_date, $to_date);

        $output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
        if (sizeof($ticketEntries) == 0) {
            //We have no entries containing ticket ids so bail
            return;
        }

        // Sort tickets by user
        usort($ticketEntries, array($this, 'compareByUserId'));

        //Update bug tracker with time registrations
        try {
            foreach ($ticketEntries as $entry) {
                $this->debug(".");

                // check for active timers - let's not update bug tracker with
                // timers still running...
                if (strlen($entry->get("timer-started-at")) != 0) {
                    // we have an active timer, bounce off!
                    $this->debug("\n");
                    $output->writeln(sprintf(
                        'SKIPPED (active timer) entry #%d: %s',
                        $entry->get('id'),
                        $entry->get('notes')
                    ));
                    continue;
                }

                //One entry may - but shouldn't - contain multiple ticket ids
                $ticketIds = $this->getTicketIds($entry);

                // store the ticketinfo
                if (!empty($ticketIds)) {
                    $checkHarvestEntries[$entry->get('id')] = $ticketIds;
                }

                //Determine base info
                $taskName             = $this->getTimetracker()->getTaskNameById($entry->get('task-id'));
                $harvestUserName      = $this->getTimetracker()->getUserNameById($entry->get("user-id"));
                $harvestProjectName   = $this->getTimetracker()->getProjectNameById($projects, $entry->get("project-id"));
                $harvestTimestamp     = $entry->get("spent-at");

                $entryText = sprintf(
                    'Entry #%d [%s/%s]: "%s" %sby %s @ %s in "%s"',
                    $entry->get('id'),
                    $entry->get('hours'),
                    $taskName,
                    $entry->get('notes'),
                    "\r\n",
                    $harvestUserName,
                    $harvestTimestamp,
                    $harvestProjectName
                );

                //In case there are several ids in an entry then distribute the the time spent evenly
                $hoursPerTicket = round(floatval($entry->get('hours')) / sizeof($ticketIds), 2);

                $worklog = new \stdClass;
                $worklog->harvestId = $entry->get('id');
                $worklog->user      = $harvestUserName;
                $worklog->userEmail = $this->getTimetracker()->getUserEmailById($entry->get('user-id'));
                $worklog->hours     = $hoursPerTicket;
                $worklog->spentAt   = $harvestTimestamp;
                $worklog->project   = $harvestProjectName;
                $worklog->taskName  = $taskName;
                $worklog->notes     = $entry->get('notes');

                // report an error if you have one single ticket entry with more than
                // a configurable number of hours straight. That's very odd.
                if ($this->getMaxEntryHours() &&
                    $hoursPerTicket > $this->getMaxEntryHours()) {
                    $output->writeln(sprintf(
                        'WARNING: More than %s hours registrered on %s: %s (%s hours). Email sent to user.',
                        $this->getMaxEntryHours(),
                        $worklog->harvestId,
                        $worklog->notes,
                        $worklog->hours
                    ));

                    $to = '"' . $this->getTimetracker()->getUserNameById($entry->get('user-id')) .
                        '" <' . $this->getTimetracker()->getUserEmailById($entry->get('user-id')) .
                        '>';
                    $subject = sprintf(
                        'BugYield warning: %s hours registered on %s. Really?',
                        $worklog->hours,
                        $worklog->notes
                    );
                    $body = array();
                    $body[] = sprintf(
                        'The following Harvest entry seems invalid due to more than %s registered hours on one task:',
                        $this->getMaxEntryHours()
                    );
                    $body[] = '';
                    $body[] = print_r($worklog, true);
                    $body[] = 'ACTION: Please review the time entry.';
                    $body[] = 'If it is actually valid, then you have to split it up in separate entries below 8 ' .
                        'hours in order to avoid this message.';
                    $body[] = '';
                    $body[] = 'NOTICE: If you have no clue what you should do to fix your time registration';
                    $body[] = 'in Harvest please ask your friendly BugYield administrator: ' .
                        self::getBugyieldEmailFrom();
                    $headers = 'From: ' . self::getBugyieldEmailFrom() .
                        "\r\n" . 'Reply-To: ' . self::getBugyieldEmailFrom() .
                        "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
                    // add CC if defined in the config
                    if (!empty($notifyOnError)) {
                        $headers .= 'Cc: ' . $notifyOnError . "\r\n";
                    }

                    if (!$this->mail($to, $subject, implode("\n", $body), $headers)) {
                        $output->writeln('  > ERROR: Could not send email to: '. $to);
                    }
                }

                foreach ($ticketIds as $id) {
                    // entries.
                    try {
                        // saveTimelogEntry() will handle whether to add or update
                        $this->debug("/");
                        $updated = $this->getBugtracker()->saveTimelogEntry($id, $worklog);
                        $this->debug("\\");

                        if ($updated) {
                            $output->writeln(sprintf(
                                'Added work to %s: %s in %s',
                                $id,
                                $worklog->notes,
                                $this->getBugtracker()->getName()
                            ));
                            $this->debug($worklog);
                        }

                        // save entries for the error checking below.
                        // This only runs/checks if a ticket has been updated.
                        if ($updated || $this->doExtendedTest()) {
                            if (empty($uniqueTicketIds) ||
                                !array_key_exists($id, $uniqueTicketIds)) {
                                $uniqueTicketIds[$id] = $id;
                            }
                        }
                    } catch (\Exception $e) {
                        $to = '"' . $this->getTimetracker()->getUserNameById($entry->get('user-id')) . '" <' .
                            $this->getTimetracker()->getUserEmailById($entry->get('user-id')) . '>';
                        $subject = $id . ': time sync exception';
                        $body = array();
                        $body[] = 'Trying to sync Harvest entry:';
                        $body[] = '';
                        $body[] = print_r($worklog, true);
                        $body[] = 'Failed with exception:';
                        $body[] = $e->getMessage();
                        $body[] = '';
                        $body[] = 'NOTICE: If you have no clue what you should do to fix your time registration';
                        $body[] = 'in Harvest please ask your friendly BugYield administrator: ' .
                            self::getBugyieldEmailFrom();
                        $headers = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' .
                            self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
                        // add CC if defined in the config
                        if (!empty($notifyOnError)) {
                            $headers .= 'Cc: ' . $notifyOnError . "\r\n";
                        }
                        $this->mail($to, $subject, implode("\n", $body), $headers);
                    }
                }
            }

            // ERROR CHECKING BELOW
            // This code will look for irregularities in the logged data,
            // namely if the bugtracker is out-of-sync with Harvest. Currently
            // this runs whenever a ticket in the bugtracker has been
            // referenced in Harvest - then all the logged entries are
            // checked.
            // TODO! THIS WILL FAIL (probably, depending on permission) IF YOU
            // HAVE JIRA MULTIUSER ENABLED as it does not log in as the
            // correct user.

            if ($this->doExtendedTest()) {
                $output->writeln('EXTENDED TEST has been enabled, all referenced tickets will be checked even ' .
                                 'if they were not updated. Set extended_test = false to disable this.');
            }
            $output->writeln(sprintf(
                'Starting error checking: %d tickets will be checked...',
                sizeof($uniqueTicketIds)
            ));

            $checkBugtrackerEntries = array();
            $possibleErrors = array();
            $worklog = null;
            $bugtrackerName   = $this->getBugtracker()->getName();

            foreach ($uniqueTicketIds as $id) {
                $this->debug(".");
                $checkBugtrackerEntries[$id] = $this->getBugtracker()->getTimelogEntries($id);
            }

            foreach ($checkBugtrackerEntries as $fbId => $harvestEntriesData) {
                foreach ($harvestEntriesData as $worklog) {
                    if (!isset($worklog->harvestId)) {
                        // probably not a Harvest linked worklog entry
                        continue;
                    }
                    if (!isset($checkBugtrackerEntries[$worklog->harvestId]) ||
                        !in_array($fbId, $checkHarvestEntries[$worklog->harvestId])) {
                        $possibleErrors[] = array($fbId => $worklog);
                    }
                }
            }

            // if we have possible error, then look up the Harvest Entries, as
            // they might just be old and therefore out of our current scope.
            // Or the entry could have been deleted or edited out, thereby
            // making Bugtracker data out-of-sync.
            if (!empty($possibleErrors)) {
                $realErrors = array();

                foreach ($possibleErrors as $data) {
                    foreach ($data as $fbId => $worklog) {
                        $errorData      = array();
                        $hUserId        = false;
                        $hUserEmail     = self::getBugyieldEmailFallback();

                        $hEntryUser     = trim(html_entity_decode($worklog->user, ENT_COMPAT, "UTF-8"));
                        $Harvest_User   = $this->getTimetracker()->getHarvestUserByFullName($hEntryUser);

                        if ($Harvest_User) {
                            $hUserId    = $Harvest_User->get("id");
                            $hUserEmail = $Harvest_User->get("email");
                        } else {
                            $output->writeln(sprintf('WARNING: Could not find email for this user: "%s"', $hEntryUser));
                            $output->writeln('-------- As the user cannot be found, the following checks will fail ' .
                                             'as well, so this entry will be skipped. Check user names for spelling ' .
                                             'errors, check ticket name for weird characters breaking the regex etc.');
                            $output->writeln(sprintf('-------- Worklog Data [BUGID %s]: %s', $fbId, "\n" .
                                                     var_export($worklog, true)));
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
                        if ($entry = $this->getTimetracker()->getEntryById($worklog->harvestId, $hUserId)) {
                            // look for the ID
                            $ticketIds = self::getTicketIds($entry);
                            if (!in_array($fbId, $ticketIds)) {
                                // Error found! The time entry still exist,
                                // but there is no reference to this bug any
                                // longer. Check that the bugtracker user
                                // matches the entry user. If not, load the
                                // proper user.
                                if ($hUserId != $entry->get("user-id")) {
                                    // hmm, this is unusual...
                                    $output->writeln(sprintf(
                                        'WARNING: We have an errornous reference from BugID %s ' .
                                        'to timeentry %s where the users do not match: %s',
                                        $fbId,
                                        $entry->get("id"),
                                        var_export($hEntryData, true)
                                    ));
                                }

                                // Add error 1 reason for later use
                                $errorData["entryNote"] = $entry->get("notes");
                                $errorData["reason"]  = sprintf(
                                    "Error 1: The time entry (%s) still exist in Harvest, but there " .
                                    "is no reference to ticket %s from the entry. This could mean " .
                                    "that you have changed or removed the ticketId in this particular " .
                                    "Harvest time entry.",
                                    $errorData["entryid"],
                                    $fbId
                                );

                                $this->debug($errorData);

                                if ($this->fixMissingReferences()) {
                                    $output->writeln("  > Fix Missing References is ON: Trying to auto-fix issue... " .
                                                     "(set fix_missing_references to false to disable this)");
                                    if ($this->getBugtracker()->deleteWorkLogEntry(
                                        $errorData["remoteId"],
                                        $errorData["bugID"]
                                    )) {
                                        $output->writeln(sprintf(
                                            "  > AUTO-FIXED an potential %s. WORKLOG HAS BEEN AUTO REMOVED BY " .
                                            "BUGYIELD!",
                                            $errorData["reason"]
                                        ));
                                        continue;
                                    }
                                    $output->writeln(sprintf(
                                        "  > AUTO-FIX FAILED in %s: %s - Reason: %s",
                                        $errorData["bugID"],
                                        $errorData["bugNote"],
                                        $errorData["reason"]
                                    ));
                                }

                                // Add error 1 to the RealErrors
                                $realErrors[] = $errorData;
                            } else {
                                continue;
                            }
                        } else {
                            // Error found! No entry found in Harvest, the
                            // Bugtracker is referring to a timeentry that
                            // does not exist anylonger.

                            // Add Error 2 reason for later use
                            $errorData["entryNote"] = "ENTRY DELETED";
                            $errorData["reason"]  = sprintf(
                                "Error 2: No entry found in Harvest, %s ticket %s is referring to a timeentry " .
                                "(%s) that does not exist anylonger.",
                                $bugtrackerName,
                                $fbId,
                                $errorData["entryid"]
                            );

                            $this->debug($errorData);

                            if ($this->fixMissingReferences()) {
                                $output->writeln("  > Fix Missing References is ON: Trying to auto-fix issue... " .
                                                 "(set fix_missing_references to false to disable this)");
                                if ($this->getBugtracker()->deleteWorkLogEntry(
                                    $errorData["remoteId"],
                                    $errorData["bugID"]
                                )
                                ) {
                                    $output->writeln(sprintf(
                                        "  > AUTO-FIXED an potential %s. REFERENCE HAS BEEN AUTO REMOVED BY BUGYIELD!",
                                        $errorData["reason"]
                                    ));
                                    continue;
                                }
                                $output->writeln(sprintf(
                                    "  > AUTO-FIX FAILED in %s: %s - Reason: %s",
                                    $errorData["bugID"],
                                    $errorData["bugNote"],
                                    $errorData["reason"]
                                ));
                            }

                            // Add error 2 to the RealErrors
                            $realErrors[] = $errorData;
                        }
                    }
                }

                if (!empty($realErrors)) {
                    $output->writeln(sprintf(
                        'ERRORs found: We have %s erroneous references from %s to Harvest. Users will be notified ' .
                        'by email, stand by...',
                        count($realErrors),
                        $bugtrackerName
                    ));

                    foreach ($realErrors as $errorData) {
                        // data for the mail
                        $unixdate         = strtotime($errorData["date"]);
                        $year             = date("Y", $unixdate); // YYYY
                        $dayno            = date("z", $unixdate)+1; // Day of the year, eg. 203
                        $harvestEntryUrl  = sprintf(
                            self::getHarvestURL() . "daily/%d/%d/%d#timer_link_%d",
                            $errorData["userid"],
                            $dayno,
                            $year,
                            $errorData["entryid"]
                        );

                        // build the mail to be sent
                        $subject  = sprintf(
                            "BugYield Synchronisation error found in %s registered %s by %s",
                            $errorData["bugID"],
                            $errorData["date"],
                            $errorData["name"]
                        );
                        $body     = sprintf(
                            "Hi %s,\nBugYield has found some inconsistencies between Harvest and data registrered" .
                            " on bug %s. Please review this error:\n\n%s\n",
                            $errorData["name"],
                            $errorData["bugID"],
                            $errorData["reason"]
                        );
                        $body     .= sprintf(
                            "\nLink to %s: %s",
                            $bugtrackerName,
                            self::getBugtrackerTicketURL(
                                $this->getBugtracker()->sanitizeTicketId($errorData["bugID"]),
                                $errorData["remoteId"]
                            )
                        );
                        $body     .= sprintf("\nLink to Harvest: %s", $harvestEntryUrl);
                        $body     .= sprintf("\n\nCurrent data from Harvest entry:\n  %s", $errorData["entryNote"]);
                        $body     .= sprintf(
                            "\n\nOutdated Harvest data logged in %s:\n  %s",
                            $bugtrackerName,
                            $errorData["bugNote"]
                        );
                        $body     .= sprintf(
                            "\n\nIMPORTANT: In order to fix this, you must manually edit the entries, e.g. by " .
                            "editing/removing the logdata from %s and subtract the time added.",
                            $bugtrackerName
                        );
                        $headers  = 'From: ' . self::getBugyieldEmailFrom() . "\r\n" . 'Reply-To: ' .
                            self::getBugyieldEmailFrom() . "\r\n" . 'X-Mailer: PHP/' . phpversion() . "\r\n";
                        // add CC if defined in the config
                        if (!empty($notifyOnError)) {
                            $headers .= 'Cc: ' . $notifyOnError . "\r\n";
                        }

                        $output->writeln(sprintf(
                            "  > Sync error found in %s: %s - Reason: %s",
                            $errorData["bugID"],
                            $errorData["bugNote"],
                            $errorData["reason"]
                        ));

                        if (!$this->mail($errorData["email"], $subject, $body, $headers)) {
                            $output->writeln(sprintf('  > Could not send email to %s', $errorData["email"]));
                            // send to admin instead
                            $this->mail(self::getBugyieldEmailFallback(), "FALLBACK: " . $subject, $body, $headers);
                        } else {
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

    // Compare harvest entries by user ID.
    public function compareByUserId($a, $b)
    {
        if ($a->{'user-id'} == $b->{'user-id'}) {
            return 0;
        }
        return $a->{'user-id'} < $b->{'user-id'} ? 1 : -1;
    }
}
