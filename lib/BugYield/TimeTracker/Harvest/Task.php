<?php

namespace BugYield\TimeTracker\Harvest;

class Task implements \BugYield\TimeTracker\Task {

  private $task;

  public function __construct(\Harvest_Task $task) {
    $this->task = $task;
  }

  public function getId() {
    return $this->task->get('id');
  }

  public function getName() {
    return $this->task->get('name');
  }

}