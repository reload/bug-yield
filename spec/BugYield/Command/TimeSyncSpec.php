<?php

namespace spec\BugYield\Command;

use BugYield\BugTracker\BugTracker;
use BugYield\Command\TitleSync;
use BugYield\Config;
use BugYield\Mailer;
use BugYield\TimeTracker\TimeTracker;
use Harvest\Model\DayEntry;
use Harvest\Model\Project;
use Harvest\Model\Result;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Prophecy\Prophet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TimeSyncSpec extends ObjectBehavior
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
    function it_should_sync_titles(
        OutputInterface $output,
        Config $config,
        BugTracker $bugtracker,
        TimeTracker $timetracker,
        Mailer $mailer
    )
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
            'notes' => '#ID-2',
            'timer-started-at' => '',
            'user-id' => 77,
            'task-id' => 66,
            'project-id' => 1,
            // No idea what format this is in.
            'spent-at' => '20181212',
            'hours' => 1,
        ]);
        $entries1[] = $this->prophesize(DayEntry::class, [
            'id' => 6,
            'notes' => 'Some random string',
            'timer-started-at' => '',
            'user-id' => 77,
            'task-id' => 66,
            'project-id' => 1,
            'spent-at' => '20181213',
            'hours' => 1.5,
        ]);

        $entries2[] = $this->prophesize(DayEntry::class, [
            'id' => 7,
            'notes' => '#ID-3 #ID-4',
            'timer-started-at' => '',
            'user-id' => 77,
            'task-id' => 66,
            'project-id' => 2,
            'spent-at' => '20181214',
            'hours' => 2,
        ]);
        $entries2[] = $this->prophesize(DayEntry::class, [
            'id' => 8,
            'notes' => '#ID-3',
            'timer-started-at' => '111111',
            'user-id' => 77,
            'task-id' => 66,
            'project-id' => 2,
            'spent-at' => '20181214',
            'hours' => 0.5,
        ]);

        $config->getProjectIds()->willReturn(['project-1', 'project-2']);
        $config->getDaysBack()->willReturn(2);
        $config->isDebug()->willReturn(false);
        $config->getEmailNotifyOnError()->willReturn('error@reload.dk');
        $config->getMaxEntryHours()->willReturn('10');
        $config->bugyield('email_from')->willReturn('harvest@reload.dk');
        $config->doExtendedTest()->willReturn(false);

        $timetracker->getProjects(['project-1', 'project-2'])->willReturn($projects);
        $timetracker->getProjectEntries('1', false, date("Ymd", time() - (86400 * 2)), date("Ymd"))->willReturn($entries1);
        $timetracker->getProjectEntries('2', false, date("Ymd", time() - (86400 * 2)), date("Ymd"))->willReturn($entries2);
        $timetracker->getProjectEntries('3', false, date("Ymd", time() - (86400 * 2)), date("Ymd"))->willReturn([]);
        $timetracker->getUserNameById(77)->willReturn('N. O. Body');
        $timetracker->getUserEmailById(77)->willReturn('nobody@reload.dk');
        $timetracker->getTaskNameById(66)->willReturn('Development');
        $timetracker->getProjectNameById($projects, 1)->willReturn('Project 1');
        $timetracker->getProjectNameById($projects, 2)->willReturn('Project 2');

        $bugtracker->getName()->willReturn('Jira');
        $bugtracker->getURL()->willReturn('http://jira.reload.dk');
        $bugtracker->extractIds('#ID-2')->willReturn(['ID-2']);
        $bugtracker->extractIds('Some random string')->willReturn([]);
        $bugtracker->extractIds('#ID-3 #ID-4')->willReturn(['ID-3', 'ID-4']);
        $bugtracker->extractIds('#ID-3')->willReturn(['ID-3']);
        $bugtracker->getTitle('ID-2')->willReturn('Ticket number 2');
        $bugtracker->getTitle('ID-3')->willReturn('Ticket number 3');
        $bugtracker->getTitle('ID-4')->willReturn('Ticket number 4');

        // This is the important part, the result we're really after.
        $bugtracker->saveTimelogEntry('ID-2', (object) [
            'harvestId' => 5,
            'user' => 'N. O. Body',
            'userEmail' => 'nobody@reload.dk',
            'hours' => 1,
            'spentAt' => '20181212',
            'project' => 'Project 1',
            'taskName' => 'Development',
            'notes' => '#ID-2',
        ])->shouldBeCalled();

        $bugtracker->saveTimelogEntry('ID-3', (object) [
            'harvestId' => 7,
            'user' => 'N. O. Body',
            'userEmail' => 'nobody@reload.dk',
            'hours' => 1,
            'spentAt' => '20181214',
            'project' => 'Project 2',
            'taskName' => 'Development',
            'notes' => '#ID-3 #ID-4',
        ])->shouldBeCalled();

        $bugtracker->saveTimelogEntry('ID-4', (object) [
            'harvestId' => 7,
            'user' => 'N. O. Body',
            'userEmail' => 'nobody@reload.dk',
            'hours' => 1,
            'spentAt' => '20181214',
            'project' => 'Project 2',
            'taskName' => 'Development',
            'notes' => '#ID-3 #ID-4',
        ])->shouldBeCalled();

        // Capture output.
        $buffer = "";
        $output->writeln(Argument::any())->will(function ($args) use (&$buffer) {
            $buffer .= trim($args[0]) . "\n";
        });

        $mailer->mail()->shouldNotBeCalled();

        // And ACTION!
        $this->callOnWrappedObject('__invoke', [$output, $config, $mailer]);

        // Eliminate variance from output.
        $buffer = preg_replace(
            '/TimeSync executed: \d{8} \d{2}:\d{2}:\d{2}/',
            'TimeSync executed: 00000000 00:00:00',
            $buffer
        );
        $buffer = preg_replace(
            '/Collecting Harvest entries between \d{8} to \d{8}/',
            'Collecting Harvest entries between 00000000 to 00000000',
            $buffer
        );
        $expectedOutput = <<<EOF
TimeSync executed: 00000000 00:00:00
Bugtracker is Jira (http://jira.reload.dk)
Verifying projects in Harvest
Working with project: Project 1                                project-1
Working with project: Project 2                                project-2
Working with project: Project 3                                project-3          ARCHIVED (Latest activity: 121299)
Collecting Harvest entries between 00000000 to 00000000
Collected 3 ticket entries
SKIPPED (active timer) entry #8: #ID-3
Starting error checking: 0 tickets will be checked...
TimeSync completed

EOF;
        try {
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
