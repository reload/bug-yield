<?php

namespace BugYield\TimeTracker;

use Symfony\Component\Yaml\Exception;

interface TimeTracker
{
    /**
     * Collect projects from time tracker.
     *
     * @param array $projectIds An array of project identifiers - ids, names or codes
     * @return array $projects
     */
    public function getProjects($projectIds);

    /**
     * Collect users from time tracker.
     */
    public function getUsers();

    /**
     * Fetch the time tracker task by id
     *
     * @param Integer $taskId
     * @return String Task name
     */
    public function getTaskNameById($taskId);

    /**
     * Return time entries from projects.
     *
     * @param string $project Project id.
     * @param boolean $only_editable Only return entries that can be edited.
     * @param Integer $from_date Date in YYYYMMDD format
     * @param Integer $to_date Date in YYYYMMDD format
     * @return array
     */
    public function getProjectEntries($project, $only_editable, $from_date, $to_date);

    /**
     * Look through the projects array and return a name.
     *
     * @todo Shouldn't be necessary if we return richer objects for projects
     *   users and entries.
     *
     * @param Array $projects array of project objects
     * @param Integer $projectId
     * @return String Name of the project
     */
    public function getProjectNameById($projects, $projectId);

    /**
     * Fetch the user name by id.
     *
     * @param Integer $userId
     * @return String Full name
     */
    public function getUserNameById($userId);

    /**
     * Fetch the user mail by id
     *
     * @todo Move to TimeTracker.
     *
     * @param Integer $userId
     * @return String Full name
     */
    public function getUserEmailById($userId);

    /**
     * Fetch the entry by id
     *
     * @param Integer $entryId
     * @param Integer|bool $userId
     * @return Result object
     */
    public function getEntryById($entryId, $userId = false);

    /**
     * Fetch the time tracker user by searching for the full name.
     *
     * This will of course make odd results if you have two or more active users with exactly the same name.
     *
     * @param String $fullname
     * @return User object
     */
    public function getHarvestUserByFullName($fullname);
}
