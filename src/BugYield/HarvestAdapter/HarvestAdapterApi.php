<?php

namespace BugYield\HarvestAdapter;

use Harvest\HarvestApi;
use Harvest\Model\DayEntry;

class HarvestAdapterApi extends HarvestApi {

  /**
   * Gets the entry specified
   *
   * Exists in adapter because: we want to have "multiple users"
   * support (?of_user=).
   *
   * <code>
   * $entry_id = 12345;
   *
   * $api = new HarvestApi();
   *
   * $result = $api->getEntry($entry_id);
   * if ($result->isSuccess()) {
   *     $entry = $result->data;
   * }
   * </code>
   *
   * @param int $entry_id Entry Identifier
   * @param bool $user_id
   * @return \Harvest\Model\Result
   */
  public function getEntry($entry_id, $user_id = false)
  {
    $url = "daily/show/" . $entry_id;

    // if the connecting user is admin and is editing entries for another user
    // @see http://www.getharvest.com/api/time-tracking#other-users
    if($user_id) {
      $url .= "?of_user=" . $user_id;
    }

    return $this->performGet($url, false);
  }

  /**
   * Update an entry
   *
   * Exists in adapter because: we want to have "multiple users"
   * support (?of_user=).
   *
   * <code>
   * $entry = new DayEntry();
   * $entry->set("id" 11111);
   * $entry->set("notes", "Test Support");
   * $entry->set("hours", 3);
   * $entry->set("project_id", 3);
   * $entry->set("task_id", 14);
   * $entry->set("spent_at", "Tue, 17 Oct 2006");
   *
   * $api = new HarvestApi();
   *
   * $result = $api->updateEntry($entry);
   * if ($result->isSuccess()) {
   *     // success logic
   * }
   * </code>
   *
   * @param \Harvest\Model\DayEntry $entry Day Entry
   * @param bool $by_another_user
   * @return \Harvest\Model\Result
   */
  public function updateEntry(DayEntry $entry, $by_another_user = true)
  {
    $url = "daily/update/$entry->id";

    // if the connecting user is admin and is editing entries for another user
    // @see http://www.getharvest.com/api/time-tracking#other-users
    if($by_another_user) {
      $url .= "?of_user=" . $entry->get("user-id");
    }

    return $this->performPost($url, $entry->toXML());
  }

  /**
   * Perform http post command.
   *
   * Exists in adapter because: We want to check if _headers["Location"]
   * exist because it gave us PHP notices about non-existing index.
   *
   * @param  string $url url of server to process request
   * @param  string $data data to be sent
   * @param string $multi
   * @return \Harvest\Model\Result
   */
  protected function performPost($url, $data, $multi = "id")
  {
    $rData = null;
    $code = null;
    $success = false;
    while (! $success) {
      $ch = $this->generatePostCurl($url, $data);
      $rData = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      if ($this->_mode == HarvestApi::RETRY && $code == "503") {
        $success = false;
        sleep($this->_headers['Retry-After']);
      } else {
        $success = true;
      }
    }
    if ("2" == substr($code, 0, 1)) {
      if ($multi == "id" && isset($this->_headers["Location"])) {
        $rData = $this->_headers["Location"];
      } elseif ($multi === true) {
        $rData = $this->parseItems($rData);
      } elseif ($multi == "raw") {
        $rData = $data;
      } else {
        $rData = $this->parseItem($rData);
      }
    }

    return new \Harvest\Model\Result($code, $rData, $this->_headers);
  }
}
