<?php 

namespace BugYield\TimeTracker;

/**
 * Base class for entries providing common utility functions.
 */
abstract class BaseEntry implements Entry {

  /**
   * Return a readable version af an entry for use in emails etc. 
   * 
   * @return string A textual representation of the entry.
   */
  public function __toString() {
    $return = array();

    $class = new ReflectionClass($this);
    foreach ($class->getMethods() as $method) {
      $name = str_replace(array('get', 'is'), '', $method->name);
      $return[] = $name . ': '. $method->invoke($this);
    }

    return implode("\n", $return);
  }

}