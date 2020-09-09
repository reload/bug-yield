<?php

namespace spec\BugYield\BugTracker;

use BugYield\BugTracker\Jira;
use PhpSpec\ObjectBehavior;

class JiraSpec extends ObjectBehavior
{
    function let()
    {
        $this->beConstructedWith([
            'url' => '',
            'username' => '',
            'password' => '',
        ]);
    }

    function it_should_parse_issue_ids_with_hash()
    {
        $this->extractIds('#PROJ-123 BANANA-123 #proj-321')->shouldReturn(['#PROJ-123', '#PROJ-321']);
    }
}
