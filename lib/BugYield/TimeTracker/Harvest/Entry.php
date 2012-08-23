<?php

namespace BugYield\TimeTracker\Harvest;

class Entry extends \BugYield\TimeTracker\BaseEntry {

  private $entry;
  private $timetracker;
  private $changed = FALSE;

  public function __construct(\Harvest_DayEntry $entry, TimeTracker $timetracker) {
    $this->entry = $entry;
    $this->timetracker = $timetracker;
  }

  public function getId() {
    return $this->entry->get('id');
  }

  public function getUserId() {
    return $this->entry->get('user-id');
  }

  public function getTaskId() {
    return $this->entry->get('task-id');
  }

  public function getProjectId() {
    return $this->entry->get('project-id');
  }

  public function getText() {
    return $this->entry->get('notes');
  }

  public function isTimerActive() {
    return (strlen($this->entry->get("timer-started-at")) != 0);
  }

  public function getTimestamp() {
    return strtotime($this->entry->get("spent-at"));
  }

  public function getHoursSpent() {
    return floatval($this->entry->get('hours'));
  }

  public function setText($text) {
    if ($text != $this->entry->get('notes')) {
      // Add CDATA tags around the notes - or Harvest will fail on chars as < > &
      // Harvest removes < and > in the website editor.
      $this->entry->set('notes', sprintf('<![CDATA[%s]]>', $text));
      $this->changed = TRUE;
    }
  }

  public function save() {
    if ($this->changed) {
      return $this->timetracker->saveEntry($this->entry);
    }
  }

}