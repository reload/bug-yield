<?php

namespace BugYield\TimeTracker\Harvest;

class User implements \BugYield\TimeTracker\User {

  private $user;

  public function __construct(\Harvest_User $user) {
    $this->user = $user;
  }

  public function getId() {
    return $this->user->get('id');
  }

  public function getEmail() {
    return $this->user->get('email');
  }

  public function getName() {
    return $this->user->get("first-name") . " " . $this->user->get("last-name");
  }
}