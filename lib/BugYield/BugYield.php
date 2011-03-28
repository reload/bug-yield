<?php

namespace BugYield;

use BugYield\Command\TimeSync;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Application;

class BugYield extends \Symfony\Component\Console\Application {

	public function __construct() {
		parent::__construct('Bug Yield', '0.1');
	
		$this->addCommands(array(new TimeSync()));
	}
	
}