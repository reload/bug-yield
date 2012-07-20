<?php

namespace BugYield;

use BugYield\Command\TitleSync;
use BugYield\Command\TimeSync;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Application;

class BugYield extends \Symfony\Component\Console\Application {

	public function __construct() {
		parent::__construct('Bug Yield', '1.0');

		$this->addCommands(array(new TimeSync()));
		$this->addCommands(array(new TitleSync()));
	}

}