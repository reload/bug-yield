<?php

namespace BugYield\TimeTracker;

interface TimeTracker {

  public function getName();

  public function getUrl();

  /**
   * Returns the URL for the Entry.
   * 
   * @param  Entry  $entry The entry
   * @return string        The entry URL
   */
  public function getEntryUrl(Entry $entry);

  /**
   * Fetch a range of entries from projects configured with the timetracker instance.
   * 
   * @param  int $fromDate           Date in YYYYMMDD format
   * @param  int $toDate             Date in YYYYMMDD format
   * @param  boolean $ignoreLocked   Wether to filter closed/billed entries? 
   *                                 They should not be updated.
   * @return array                   An array of Entry objects
   */
  public function getEntries($fromDate, $toDate, $ignoreLocked);

  /**
   * Fetch an entry by id.
   * 
   * @param   string $id                  The entry id.
   * @param   string $userId              The user id on which behalf to retrive the ticket.
   * @return  BugYield\TimeTracker\Entry  Entry object
   */
  public function getEntry($id, $userId = FALSE);

  public function getProjects();

  public function getProject($id);

  public function getTask($id);

  public function getUsers();

  /**
   * Fetch an user by his/her id.
   * 
   * @param  string $id                A user id.
   * @return BugYield\TimeTracker\User the corresponding user
   *                                   FALSE if not found.
   */
  public function getUser($id);

  /**
   * Fetch an user by his/her full name.
   * 
   * @param  string $name              A full user name.
   * @return BugYield\TimeTracker\User the corresponding user
   *                                   FALSE if not found.
   */
  public function getUserByFullName($name);

}