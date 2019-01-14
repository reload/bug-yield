<?php

namespace BugYield\TimeTracker;

use Harvest\HarvestApi;
use Harvest\Model\DayEntry;
use Harvest\Model\Result;
use Harvest\Model\Range;
use Harvest\Model\User;

class Harvest extends TimeTrackerBase
{
    /**
     * Harvest client.
     * @var HarvestApi
     */
    protected $harvest;

    public function __construct($timetrackerConfig)
    {
        $harvest = new HarvestApi();
        $harvest->setAccount($timetrackerConfig['account']);
        $harvest->setUser($timetrackerConfig['username']);
        $harvest->setPassword($timetrackerConfig['password']);
        $harvest->setRetryMode(HarvestApi::RETRY);
        $this->harvest = $harvest;
    }

    /**
     * Collect projects from Harvest
     *
     * @todo Should create a TimeTracker to dependency inject just like
     * BugTracker, and move this there.
     *
     * @param array $projectIds An array of project identifiers - ids, names or codes
     * @return array $projects
     */
    public function getProjects($projectIds)
    {
        $projects = array();

        //Prepare by getting all projects
        $result = $this->harvest->getProjects();
        $harvestProjects = ($result->isSuccess()) ? $result->get('data') : array();

        //Collect all requested projects
        $unknownProjectIds = array();
        foreach ($projectIds as $projectId) {
            if (is_numeric($projectId)) {
                //If numeric id then try to get a specific project
                $result = $this->harvest->getProject($projectId);
                if ($result->isSuccess()) {
                    $projects[] = $result->get('data');
                } else {
                    $unknownProjectIds[] = $projectId;
                }
            } else {
                $identified = false;
                foreach ($harvestProjects as $project) {
                    if (is_string($projectId)) {
                        //If "all" then add all projects
                        if ($projectId == 'all') {
                            $projects[] = $project;
                            $identified = true;
                        } elseif ($project->get('name') == $projectId || $project->get('code') == $projectId) {
                            //If string id then get project by name or
                            //shorthand (code)
                            $projects[] = $project;
                            $identified = true;
                        }
                    }
                }
                if (!$identified) {
                    $unknownProjectIds[] = $projectId;
                }
            }
        }
        return $projects;
    }

    /**
     * Collect users from Harvest
     */
    public function getUsers()
    {
        if (is_array($this->harvestUsers)) {
            return $this->harvestUsers;
        }

        // Prepare by getting all projects.
        $result = $this->getUsers();
        $harvestUsers = ($result->isSuccess()) ? $result->get('data') : array();

        $this->harvestUsers = $harvestUsers;

        // Array of Harvest_User objects.
        return $harvestUsers;
    }

    /**
     * Fetch the Harvest Task by id
     *
     * @param Integer $harvest_task_id
     * @return String Task name
     */
    public function getTaskNameById($harvest_task_id)
    {
        $taskname = "Unknown";

        if (!is_array($this->harvestTasks) && !isset($this->harvestTasks[$harvest_task_id])) {
            //Prepare by getting all projects
            $result = $this->harvest->getTasks();
            $harvestTasks = ($result->isSuccess()) ? $result->get('data') : array();

            $this->harvestTasks = $harvestTasks;
        }

        if (isset($this->harvestTasks[$harvest_task_id])) {
            $Harvest_Task = $this->harvestTasks[$harvest_task_id];
            $taskname = $Harvest_Task->get("name");
        }

        return $taskname;
    }

    public function getProjectEntries($project, $only_editable, $from_date, $to_date) {
        $range = new Range($from_date, $to_date);
        $result = $this->harvest->getProjectEntries($project, $range);
        $ticketEntries = array();

        if ($result->isSuccess()) {
            foreach ($result->get('data') as $entry) {
                // Check that the entry is actually writable.
                if ($only_editable == true &&
                    ($entry->get("is-closed") == "true" ||
                     $entry->get("is-billed") == "true")) {
                    continue;
                }

                $ticketEntries[] = $entry;
            }
        }

        return $ticketEntries;
    }

    /**
     * Look through the projects array and return a name
     *
     * @todo Shouldn't be necessary if we return richer objects for projects
     *   and entries.
     *
     * @param Array $projects array of Harvest_Project objects
     * @param Integer $projectId
     * @return String Name of the project
     */
    public function getProjectNameById($projects, $projectId)
    {
        $projectName = "Unknown";
        foreach ($projects as $project) {
            if ($project->get("id") == $projectId) {
                $projectName = $project->get("name");
                break;
            }
        }
        return $projectName;
    }

    /**
     * Fetch the Harvest User by id
     *
     * @param Integer $harvest_user_id
     * @return String Full name
     */
    public function getUserNameById($harvest_user_id)
    {
        $username = "Unknown";

        $harvestUsers = $this->getUsers();

        if (isset($harvestUsers[$harvest_user_id])) {
            $Harvest_User = $harvestUsers[$harvest_user_id];
            $username = $Harvest_User->get("first-name") . " " . $Harvest_User->get("last-name");
        }

        return $username;
    }

    /**
     * Fetch the Harvest User Email by id
     *
     * @todo Move to TimeTracker.
     *
     * @param integer $harvest_user_id
     * @return string|null email or null if not found.
     */
    public function getUserEmailById($harvest_user_id)
    {
        $email = null;

        $harvestUsers = $this->getUsers();

        if (isset($harvestUsers[$harvest_user_id])) {
            $Harvest_User = $harvestUsers[$harvest_user_id];
            $email = $Harvest_User->get("email");
        }

        return $email;
    }

    /**
     * Fetch the Harvest Entry by id
     *
     * @param Integer $harvestEntryId
     * @param Integer|bool $user_id
     * @return Result object
     */
    public function getEntryById($harvestEntryId, $user_id = false)
    {
        $entry = false;

        $result = $this->harvest->getEntry($harvestEntryId, $user_id);

        if ($result->isSuccess()) {
            $entry = $result->get('data');
        }

        return $entry;
    }

    /**
     * Fetch the Harvest user by searching for the full name
     * This will of course make odd results if you have two or more active users with exactly the same name...
     *
     * @param String $fullname
     * @return User object
     */
    public function getHarvestUserByFullName($fullname)
    {
        $user = false;
        $fullname = trim($fullname);

        foreach ($this->harvest->getUsers() as $Harvest_User) {
            // only search for active users.
            // prey that you do not have two users with identical names. TODO this is a possible bug in spe
            if ($Harvest_User->get("is-active") == "false") {
                continue;
            }

            $tmpFullName = trim($Harvest_User->get("first-name") . " " . $Harvest_User->get("last-name"));
            if ($fullname == $tmpFullName) {
                // yay, we have a winner! :-)
                $user = $Harvest_User;
                break;
            }
        }

        return $user;
    }

    public function updateEntry($entry)
    {
        return $this->harvest->updateEntry($entry);
    }
}
