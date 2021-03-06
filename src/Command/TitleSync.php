<?php

namespace BugYield\Command;

use BugYield\Config;
use Symfony\Component\Console\Output\OutputInterface;

class TitleSync extends BugYieldCommand
{

    /**
     * Invoke TitleSync command.
     */
    public function __invoke(OutputInterface $output, Config $config)
    {
        $output->writeln('TitleSync executed: ' . date('Ymd H:i:s'));
        $output->writeln(sprintf(
            'Bugtracker is %s (%s)',
            $this->getBugtracker()->getName(),
            $this->getBugtracker()->getURL()
        ));
        $output->writeln('Verifying projects in Harvest');

        $projects = $this->getTimetracker()->getProjects($config->getProjectIds());
        if (sizeof($projects) == 0) {
            // We have no projects to work with so bail.
            if ($config->getTimetrackerProjects()) {
                $output->writeln(sprintf(
                    'Could not find any projects matching: %s',
                    implode(',', $config->getTimetrackerProjects())
                ));
            } else {
                $output->writeln(sprintf('Could not find any configured projects matching.'));
            }
            return;
        }

        $projects = array_filter($projects, function ($project) use ($output) {
            if ($project->get("active") == "false") {
                $output->writeln(sprintf(
                    "Project %s %s is archived (Latest activity: %s), ignoring",
                    self::mbStrPad($project->get("name"), 40, " "),
                    self::mbStrPad($project->get("code"), 18, " "),
                    $project->get("hint-latest-record-at")
                ));
                return false;
            }
            $output->writeln(sprintf(
                'Working with project: %s %s',
                self::mbStrPad($project->get("name"), 40, " "),
                self::mbStrPad($project->get("code"), 18, " ")
            ));
            return true;
        });

        if (sizeof($projects) == 0) {
            $output->writeln('No active projects.');
            return;
        }

        $from_date      = date("Ymd", time() - (86400 * $config->getDaysBack()));
        $to_date        = date("Ymd");

        $output->writeln(sprintf(
            "Collecting Harvest entries between %s to %s",
            $from_date,
            $to_date
        ));

        // As we'd like to update Harvest entries, we'll only work on
        // non-locked entries.
        $output->writeln("-- Ignoring entries already billed or otherwise closed.");
        $ticketEntries = array();
        foreach ($projects as $project) {
            $entries = $this->getTimetracker()->getProjectEntries($project->get('id'), true, $from_date, $to_date);
            foreach ($entries as $entry) {
                $ids = $this->getBugtracker()->extractIds($this->stripTitles($entry->get('notes')));
                if (sizeof($ids) > 0) {
                    //If the entry has ticket ids it is a ticket entry.
                    $ticketEntries[] = $entry;
                }
            }
        }

        $output->writeln(sprintf('Collected %d ticket entries', sizeof($ticketEntries)));
        if (sizeof($ticketEntries) == 0) {
            //We have no entries containing ticket ids so bail
            return;
        }

        //Update Harvest entries with bug tracker ticket titles in the format [ticket-id]([ticket-title])
        try {
            foreach ($ticketEntries as $entry) {
                $this->debug(".");

                // check for active timers - if we update the entry, then the
                // timer will be disrupted, and odd things start to happen :-/
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

                // One entry may - but shouldn't - contain multiple ticket ids.
                $titles = [];
                foreach ($this->getBugtracker()->extractIds($this->stripTitles($entry->get('notes'))) as $ticketId) {
                    //Get the case title.
                    $this->debug("/");

                    try {
                        $titles[$ticketId] = $this->getBugtracker()->getTitle($ticketId);
                    } catch (\Throwable $e) {
                        $output->writeln(sprintf(
                            'WARNING: Title for TicketID %s could not be found. Probably wrong ID',
                            $ticketId
                        ));

                        error_log(
                            date("d-m-Y H:i:s") . " | " . __CLASS__ . " FAILED: " .
                            $ticketId . " >> " . $e->getMessage() . "\n",
                            3,
                            "error.log"
                        );
                    }
                    $this->debug("\\");
                }

                $newNote = $this->injectTitles($entry->get('notes'), $titles);
                if ($newNote) {
                    $entry->set('notes', $newNote);

                    // Update the entry in Harvest.
                    $result = $this->getTimetracker()->updateEntry($entry);
                    if ($result->isSuccess()) {
                        $output->writeln(sprintf(
                            'Updated entry %s: %s',
                            $entry->get('id'),
                            $entry->get('notes')
                        ));
                    } else {
                        $errormsg[] = sprintf(
                            'FAILED (HTTP Code: %d) to update entry %s: %s (EntryDate: %s)',
                            $result->get('code'),
                            $entry->get('id'),
                            $entry->get('notes'),
                            $entry->get('created-at')
                        );

                        foreach ($errormsg as $msg) {
                            $output->writeln($msg);
                            error_log(date("d-m-Y H:i:s") . " | " . $msg . "\n", 3, "error.log");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $output->writeln('Error communicating with bug tracker: ' . $e->getMessage());
        }

        $this->debug("\n");
        $output->writeln("TitleSync completed");
    }

    /**
     * Inject or replace titles in note.
     *
     * @param array<string, string> $titles
     *   Ticket id to title mapping.
     *
     * @return false|string
     */
    public function injectTitles(string $note, array $titles)
    {
        $newNote = $note;
        foreach ($titles as $ticketId => $title) {
            // Escape bracket in title. The replacement is backslash hell, the
            // first four is to get a single backslash in the replacement. It
            // needs to doubled twice, once for the string and another time
            // for the rexeg engine. The \1 simply refers to the first capture
            // group in the rexex (the parenthesis matching [ or ]).
            $title = preg_replace('/(\[|\])/', '\\\\\1', $title);

            // If the title ends in a backslash, pad it with a space so the
            // closing ] doesn't look like an escaped ].
            $title = preg_replace('/\\\\$/', '\\\\ ', $title);

            // Use a negative lookbehind assertion "(?<!\\\\)" to only match ]
            // not preceded by a backslash. The backslash is quadrupled again
            // to get past two levels of escaping.
            preg_match('/' . $ticketId . '(?:\\[(.*?)(?<!\\\\)\\])?/i', $newNote, $matches);
            if (isset($matches[1])) {
                if ($matches[1] != $title) {
                    // Entry note includes ticket title it does not match
                    // current title so update it. Uses the same regex as
                    // above without the capture groups.
                    $newNote = preg_replace(
                        '/' . $ticketId . '(\\[.*?(?<!\\\\)\\])/i',
                        $ticketId . '[' . $title . ']',
                        $newNote
                    );
                }
            } else {
                // Entry note does not include ticket title so add it.
                $newNote = preg_replace(
                    '/' . $ticketId . '/i',
                    strtoupper($ticketId) . '[' . $title . ']',
                    $newNote
                );
            }
        }

        return $newNote === $note ? false : $newNote;
    }
}
