<?php

namespace spec\BugYield\Command;

use BugYield\BugTracker\BugTracker;
use BugYield\Command\TitleSync;
use BugYield\Config;
use BugYield\TimeTracker\TimeTracker;
use Harvest\Model\DayEntry;
use Harvest\Model\Project;
use Harvest\Model\Result;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Prophecy\Prophet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TitleSyncSpec extends ObjectBehavior
{
    protected $prophet;

    function let(Config $config, BugTracker $bugtracker, TimeTracker $timetracker)
    {
        // We need a Prophet to create mocks on the fly.
        $this->prophet = new Prophet();
        $this->beConstructedWith($config, $bugtracker, $timetracker);
    }

    function letGo()
    {
        // Check that our mocked objects has been called as they should.
        $this->prophet->checkPredictions();
    }

    /*
     * This is rather involved, but the code was clearly not written for
     * testing, so it's positive that it's not worse than this.
     */
    function it_should_sync_titles(OutputInterface $output, Config $config, BugTracker $bugtracker, TimeTracker $timetracker)
    {
        $projects[] = $this->prophesize(Project::class, [
            'id' => '1',
            'code' => 'project-1',
            'active' => 'true',
            'name' => 'Project 1',
        ]);
        $projects[] = $this->prophesize(Project::class, [
            'id' => '2',
            'code' => 'project-2',
            'active' => 'true',
            'name' => 'Project 2',
        ]);
        $projects[] = $this->prophesize(Project::class, [
            'id' => '3',
            'code' => 'project-3',
            'active' => 'false',
            'name' => 'Project 3',
            'hint-latest-record-at' => '121299',
        ]);

        $entries1[] = $this->prophesize(DayEntry::class, [
            'id' => 5,
            'notes' => 'ID-2',
            'timer-started-at' => '',
        ]);
        $entries1[] = $this->prophesize(DayEntry::class, [
            'id' => 6,
            'notes' => 'Some random string',
            'timer-started-at' => '',
        ]);

        $entries2[] = $this->prophesize(DayEntry::class, [
            'id' => 7,
            'notes' => 'ID-3 ID-4',
            'timer-started-at' => '',
        ]);
        $entries2[] = $this->prophesize(DayEntry::class, [
            'id' => 8,
            'notes' => 'ID-3',
            'timer-started-at' => '111111',
        ]);

        $config->getProjectIds()->willReturn(['project-1', 'project-2']);
        $config->getDaysBack()->willReturn(2);
        $config->isDebug()->willReturn(false);

        $timetracker->getProjects(['project-1', 'project-2'])->willReturn($projects);
        $timetracker->getProjectEntries('1', true, date("Ymd", time() - (86400 * 2)), date("Ymd"))->willReturn($entries1);
        $timetracker->getProjectEntries('2', true, date("Ymd", time() - (86400 * 2)), date("Ymd"))->willReturn($entries2);

        $bugtracker->getName()->willReturn('Jira');
        $bugtracker->getURL()->willReturn('http://jira.reload.dk');
        $bugtracker->extractIds('ID-2')->willReturn(['ID-2']);
        $bugtracker->extractIds('Some random string')->willReturn([]);
        $bugtracker->extractIds('ID-3 ID-4')->willReturn(['ID-3', 'ID-4']);
        $bugtracker->extractIds('ID-3')->willReturn(['ID-3']);
        $bugtracker->getTitle('ID-2')->willReturn('Ticket number 2');
        $bugtracker->getTitle('ID-3')->willReturn('Ticket number 3');
        $bugtracker->getTitle('ID-4')->willReturn('Ticket number 4');

        // Set the get('notes') to return what was just set.
        $setNotes = function ($args, $entry) {
            $entry->get('notes')->willReturn($args[1]);
        };
        $entries1[0]->set('notes', 'ID-2[Ticket number 2]')->will($setNotes);
        $entries2[0]->set('notes', 'ID-3[Ticket number 3] ID-4')->will($setNotes);
        $entries2[0]->set('notes', 'ID-3[Ticket number 3] ID-4[Ticket number 4]')->will($setNotes);

        // This is the important part, the result we're really after.
        $success = $this->prophet->prophesize(Result::class);
        $success->isSuccess()->willReturn(true);
        $timetracker->updateEntry($entries1[0])->willReturn($success);
        $timetracker->updateEntry($entries2[0])->willReturn($success);

        // Capture output.
        $buffer = "";
        $output->writeln(Argument::any())->will(function ($args) use (&$buffer) {
            $buffer .= trim($args[0]) . "\n";
        });

        // And ACTION!
        $this->callOnWrappedObject('__invoke', [$output, $config]);

        // Eliminate variance from output.
        $buffer = preg_replace(
            '/TitleSync executed: \d{8} \d{2}:\d{2}:\d{2}/',
            'TitleSync executed: 00000000 00:00:00',
            $buffer
        );
        $buffer = preg_replace(
            '/Collecting Harvest entries between \d{8} to \d{8}/',
            'Collecting Harvest entries between 00000000 to 00000000',
            $buffer
        );
        $expectedOutput = <<<EOF
TitleSync executed: 00000000 00:00:00
Bugtracker is Jira (http://jira.reload.dk)
Verifying projects in Harvest
Working with project: Project 1                                project-1
Working with project: Project 2                                project-2
Project Project 3                                project-3          is archived (Latest activity: 121299), ignoring
Collecting Harvest entries between 00000000 to 00000000
-- Ignoring entries already billed or otherwise closed.
Collected 3 ticket entries
Updated entry 5: ID-2[Ticket number 2]
Updated entry 7: ID-3[Ticket number 3] ID-4[Ticket number 4]
SKIPPED (active timer) entry #8: ID-3
TitleSync completed

EOF;
        try {
            print_r($buffer);
            expect($buffer)->toBe($expectedOutput);
        } catch (\Exception $e) {
            print($buffer);
            throw $e;
        }
    }

    protected function prophesize($class, $data)
    {
        $project = $this->prophet->prophesize($class);
        foreach ($data as $name => $value) {
            $project->get($name)->willReturn($value);
        }
        return $project;
    }
}
