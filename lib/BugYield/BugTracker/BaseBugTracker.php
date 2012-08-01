<?php

namespace BugYield\BugTracker;

/**
 * Assembly of common implementations of bug tracker functionality.
 */
abstract class BaseBugTracker implements \BugYield\BugTracker\BugTracker {

  protected $config;

  public function __construct($config) {
    $this->config = $config;
    $this->assertConfigurationAvailable(array('url', 'username', 'password'));
  }

  protected function assertConfigurationAvailable(array $entries) {
    // Make sure we have the required configuration options
    foreach ($entries as $entry) {
      if (!isset($this->config[$entry])) {
        throw new \Exception('Missing configuration entry '. $entry);
      }
    }
  }

  public function getUrl() {
    return $this->config['url'];
  }

  public function getTicketUrl($ticketId, $subTicketId = NULL) {
    $urlTicketPattern = (empty($this->config['url_ticket_pattern'])) ? $this->getTicketUrlPattern() : $this->config['url_ticket_pattern'];
    return sprintf($this->getUrl() . $urlTicketPattern, $ticketId, $subTicketId);
  }

  abstract protected function getTicketUrlPattern();

  public function extractTicketIds($string) {
    $ids = array();
    if (preg_match_all($this->getTicketIdPattern(), $string, $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
  }

  abstract protected function getTicketIdPattern(); 

}
