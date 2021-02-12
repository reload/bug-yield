<?php

namespace spec\BugYield\Command;

use BugYield\BugTracker\BugTracker;
use BugYield\Command\BugYieldCommand;
use BugYield\Config;
use BugYield\TimeTracker\TimeTracker;
use PhpSpec\ObjectBehavior;

class BugYieldCommandSpec extends ObjectBehavior
{
    function let(Config $config, BugTracker $bugtracker, TimeTracker $timetracker)
    {
        // We can only tests non-abstract classes, so use a test subclass.
        $this->beAnInstanceOf('spec\BugYield\Command\ConcreteBugYieldCommand');
        $this->beConstructedWith($config, $bugtracker, $timetracker);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(ConcreteBugYieldCommand::class);
    }

    function it_should_strip_titles_properly(BugTracker $bugtracker)
    {
        $title = 'AA-1[Some title BB-1] AA-2 AA-3[BB-2]';

        // Calling extractIds with the unstripped title will return all issue
        // ids.
        $bugtracker->extractIds($title)->willReturn([
            'AA-1',
            'AA-2',
            'AA-3',
            'BB-1',
            'BB-2',
        ]);
        $this->stripTitles($title)->shouldReturn('AA-1 AA-2 AA-3');
    }

    function it_should_handle_escaped_backslashes_in_titles(BugTracker $bugtracker)
    {
        $title = 'AA-1[Some \\[title\\] BB-1] AA-2 AA-3[BB-2]';

        // Calling extractIds with the unstripped title will return all issue
        // ids.
        $bugtracker->extractIds($title)->willReturn([
            'AA-1',
            'AA-2',
            'AA-3',
            'BB-1',
            'BB-2',
        ]);
        $this->stripTitles($title)->shouldReturn('AA-1 AA-2 AA-3');
    }

    function it_shouldnt_strip_ticket_ids_in_random_brackets(BugTracker $bugtracker)
    {
        $title = '[Some AA-1 ticket] \\[AA-2\\]';

        // Calling extractIds with the unstripped title will return all issue
        // ids.
        $bugtracker->extractIds($title)->willReturn([
            'AA-1',
            'AA-2',
        ]);
        $this->stripTitles($title)->shouldReturn('[Some AA-1 ticket] \\[AA-2\\]');
    }
}

class ConcreteBugYieldCommand extends BugYieldCommand {}
