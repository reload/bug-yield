<?php

namespace BugYield\TimeTracker;

/**
 * An entry represents a entry in a time tracker system ie.
 * when a user spent some time working on a task in a project.
 */
interface Entry {

  /**
   * Return the id of the entry in the time tracker system
   * 
   * @return string The entry id
   */
  public function getId();

  /**
   * Return the id for the user spending the time in the time tracker 
   * system.
   * 
   * @return string The user id
   */
  public function getUserId();

  /**
   * Return the id of the task which the time was spent on in the
   * time tracker.
   * 
   * @return string The task id
   */
  public function getTaskId();

  /**
   * Return the id of the project which the time was spent on in the
   * time tracker system.
   * 
   * @return string The project id.
   */
  public function getProjectId();

  /**
   * Return text, notes etc. associated with the time spent.
   * This will usually be text entered by the user.
   * 
   * @return string The text.
   */
  public function getText();

  /**
   * Update the text associated with the time spent.
   * 
   * @param stirng $text The updated text.
   */
  public function setText($text);

  /**
   * Return whether there is currently begin registered time for this
   * entry.
   * 
   * @return boolean Whether the entry timer is active.
   */
  public function isTimerActive();

  /**
   * Return the timestamp for when the entry was entered.
   * 
   * @return int The entry timestamp. 
   */
  public function getTimestamp();

  /**
   * The number of hours spent.
   * 
   * @return float The number of hours spent.
   */
  public function getHoursSpent();

  /**
   * Save the entry in the bug tracking system.
   * 
   * @return bool Whether the entry was saved.
   */
  public function save();

}