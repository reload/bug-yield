<?php

namespace BugYield\TimeTracker\Harvest;

class TimeTracker implements \BugYield\TimeTracker\TimeTracker {

  private $config;
  private $api;

  function __construct($config) {
    $this->config = $config;
    $this->assertConfigurationAvailable(array('account', 'username', 'password'));

    $this->api = new \HarvestAPI();
    $this->api->setAccount($this->config['account']);
    $this->api->setUser($this->config['username']);
    $this->api->setPassword($this->config['password']);
    
    if (isset($this->config['ssl'])) {
      $this->api->setSSL($this->config['ssl']);
    }
  }

  protected function assertConfigurationAvailable(array $entries) {
    // Make sure we have the required configuration options
    foreach ($entries as $entry) {
      if (!isset($this->config[$entry])) {
        throw new \Exception('Missing configuration entry '. $entry);
      }
    }
  }

  public function getName() {
    return 'Harvest';
  }

  public function getUrl() {
    $http = ($this->config['ssl'] == true ) ? 'https://' : 'https://';
    return $http . $this->config['account'] . '.harvestapp.com/';
  }

  public function getEntryUrl(\BugYield\TimeTracker\Entry $entry) {
    return sprintf('%sdaily/%d/%d/%d#timer_link_%d', 
                    $this->getUrl() , 
                    $entry->getUserId(),
                    date('z', $unixdate) + 1,
                    date('Y',$unixdate),
                    $entry->getId()
                  );
  }

  public function getEntries($fromDate = NULL, $toDate = NULL, $ignoreLocked = TRUE) {
    // Set default values
    if (!is_numeric($fromDate)) {
      $fromDate = "19000101";
    }
    if (!is_numeric($toDate)) {
      $toDate = date('Ymd');
    }

    //Collect the entries entries
    $entries = array();
    foreach ($this->getProjects() as $project) {      
      $range = new \Harvest_Range($fromDate, $toDate);

      $result = $this->api->getProjectEntries($project->getId(), $range);
      if ($result->isSuccess()) {
        foreach ($result->get('data') as $entry) {
          // Check that the entry is actually writeable
          if ($ignoreLocked && 
              ($entry->get("is-closed") == "true" || $entry->get("is-billed") == "true")) {
            continue;
          }
          $entries[] = new Entry($entry, $this);
        }
      }
    }

    return $entries;
  }

  public function getEntry($id, $userId = FALSE) {
    $entry = FALSE;
    
    $result = $this->api->getEntry($id, $userId);
  
    if ($result->isSuccess()) {
      $entry = $result->get('data');
    }
    
    return new Entry($entry, $this);
  }

  private function getProjectIds() {
    $projectIds = $this->config['projects'];
    if (!is_array($projectIds)) {
      $projectIds = explode(',', $projectIds);
      array_walk($projectIds, 'trim');
    }
    return $projectIds;
  }

  public function getProjects() {
    $projects = array();

    //Prepare by getting all projects
    $result = $this->api->getProjects();
    $harvestProjects = ($result->isSuccess()) ? $result->get('data') : array();

    //Collect all requested projects
    $unknownProjectIds = array();
    foreach ($this->getProjectIds() as $projectId) {
      if (is_numeric($projectId)) {
        //If numeric id then try to get a specific project
        $result = $this->api->getProject($projectId);
        if ($result->isSuccess()) {
          $projects[] = $result->get('data');
        } else {
          $unknownProjectIds[] = $projectId;
        }
      } else {
        $identified = false;
        foreach ($harvestProjects as $project) {
          if (is_string($projectId)) {
            //If "all" then add all projects
            if ($projectId == 'all') {
              $projects[] = $project;
              $identified = true;
            }
            //If string id then get project by name or shorthand (code)
            elseif ($project->get('name') == $projectId || 
                    $project->get('code') == $projectId) {
              $projects[] = $project;
              $identified = true;
              break;
            }
          }
        }
        if (!$identified) {
          $unknownProjectIds[] = $projectId;
        }
      }
    }

    array_walk($projects, function(&$p) {
      $p = new Project($p);
    });
    return $projects;
  }

  public function getProject($id) {
    $project = FALSE;

    $result = $this->api->getProject($id);
    if ($result->isSuccess()) {
      $project = $result->get('data');
    }

    return new Project($project);
  }

  public function getTask($id) {
    $task = FALSE;

    $result = $this->api->getTask($id);
    if ($result->isSuccess()) {
      $task = $result->get('data');
    }

    return new Task($task);    
  }

  public function getUsers() {
    static $users;
    if (isset($users)) {
      return $users;
    }

    $result = $this->api->getUsers();
    $users = ($result->isSuccess()) ? $result->get('data') : array();

    array_walk($users, function(&$u) {
      $u = new User($u);
    });
    return $users;
  }

  public function getUser($id) {
    $users = $this->getUsers();
    return (isset($users[$id])) ? $users[$id] : FALSE;
  }

  public function getUserByFullName($name) {
    $return = FALSE;
    $name = trim($name);
    
    foreach ($this->getUsers() as $user) {
      // Only search for active users.
      // Prey that you do not have two users with identical names. 
      // TODO this is a possible bug in spe
      if ($user->get("is-active") == "false") {
        continue;
      }

      $userName = trim($user->get("first-name") . " " . $user->get("last-name"));
      if ($name == $userName) {
        // yay, we have a winner! :-)
        $return = new User($user);
        break;
      } 
    }

    return $return;
  }

  public function saveEntry(\Harvest_DayEntry $entry) {
    $result = $this->api->updateEntry($entry);
    // TODO: Consider throwing an exception here
    // Check $result->get('code')
    return $result->isSuccess();
  }

}