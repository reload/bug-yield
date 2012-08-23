<?php

namespace BugYield\BugTracker\FogBugz;

class Ticket implements \BugYield\BugTracker\Ticket {

  private $case;

  public function __construct($case) {
    $this->case = $case;
  }

  public function getTitle() {
    return $this->case->_data['sTitle'];
  }

}