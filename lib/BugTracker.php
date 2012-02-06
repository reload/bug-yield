<?php

interface BugTracker {
  public function getApi($url, $username, $password);
  public function getTitle($ticketId);
  public function extractIds($string);
}

class FogBugzBugTracker implements BugTracker {

  private $api = NULL;

  public function getApi($url, $username, $password) {
    $this->api = new \FogBugz($url, $username, $password);
    $this->api->logon();
  }

  public function getTitle($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $response = $this->api->search($ticketId, 'sTitle', 1);

    if ($case = array_shift($response->_data)) {
      return $case->_data['sTitle'];
    }

    return FALSE;
  }

  public function extractIds($string) {
    $ids = array();
    if (preg_match_all('/(#\d+)/', $string, $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
  }
}

class JiraBugTracker implements BugTracker {

  private $api = NULL;
  private $token = NULL;

  public function getApi($url, $username, $password) {
    $this->api= new SoapClient($url . '/rpc/soap/jirasoapservice-v2?wsdl');
    $this->token = $this->api->login($username, $password);
  }

  public function getTitle($ticketId) {
    $ticketId = ltrim($ticketId, '#');
    $response = $this->api->getIssue($this->token, $ticketId);

    if (is_object($response) && isset($response->summary)) {
      return $response->summary;
    }

    return FALSE;
  }

  public function extractIds($string) {
    $ids = array();
    if (preg_match_all('/(#[A-Z]+-\d+)/', $string, $matches)) {
      $ids = $matches[1];
    }
    return array_unique($ids);
  }
}
