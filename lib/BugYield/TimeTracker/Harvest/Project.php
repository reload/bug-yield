<?php

namespace BugYield\TimeTracker\Harvest;

class Project implements \BugYield\TimeTracker\Project {

  private $project;

  public function __construct(\Harvest_Project $project) {
    $this->project = $project;
  }

  public function getId() {
    return $this->project->get('id');
  }

  public function getName() {
    return $this->project->get('name');
  }

  public function getCode() {
    return $this->project->get('code');
  }

  public function isActive() {
    return $this->project->get("active") !== "false";
  }

  public function getLatestActivity() {
    return $this->project->get("hint-latest-record-at");
  }

}