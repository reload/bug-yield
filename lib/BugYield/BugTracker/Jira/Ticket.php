<?php

namespace BugYield\BugTracker\Jira;

class Ticket implements \BugYield\BugTracker\Ticket {
  
  private $response;

  public function __construct($response) {
    $this->response = $response;
  }

  public function getTitle() {
    return $this->response->summary;
  }

}