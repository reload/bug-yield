<?php

namespace BugYield\TimeTracker;

interface Project {

  public function getId();

  public function getName();

  public function getCode();

  public function isActive();

  public function getLatestActivity();
}