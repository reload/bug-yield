<?php

namespace BugYield\Command;

use Symfony\Component\Yaml\Yaml;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Command\Command;

abstract class BugYieldCommand extends \Symfony\Component\Console\Command\Command {

	private $harvestConfig;
	private $fogbugzConfig;
	
	protected function configure() {
		$this->addOption('config', NULL, InputOption::VALUE_OPTIONAL, 'Path to the configuration file', 'config.yml');
	}
	
  protected function getHarvestApi() {
  	$harvest = new \HarvestAPI();
    $harvest->setAccount($this->harvestConfig['account']);
    $harvest->setUser($this->harvestConfig['username']);
    $harvest->setPassword($this->harvestConfig['password']);
    $harvest->setSSL($this->harvestConfig['account']);
    return $harvest;
  }
  
  protected function getHarvestProjects() {
  	return $this->harvestConfig['projects'];
  }
  
  protected function getFogBugzApi() {
  	$fogbugz = new \FogBugz($this->fogbugzConfig['url'], $this->fogbugzConfig['username'], $this->fogbugzConfig['password']);
  	$fogbugz->logon();
  	return $fogbugz;
  }
  
  protected function loadConfig(InputInterface $input) {
  	$config_file = $input->getOption('config');
  	if (file_exists($config_file)) {
      $config = Yaml::load($config_file);
      $this->harvestConfig = $config['harvest'];
      $this->fogbugzConfig = $config['fogbugz'];
  	}
  }
}