<?php

use Symfony\Component\ClassLoader\ClassLoader;

// Composer autoloader (PSR-4).
require 'vendor/autoload.php';

// Setup Symfony classloader and components (PSR-0).
$loader = new ClassLoader();
$loader->setUseIncludePath(true);
$loader->addPrefixes(array(
  'BugYield' => __DIR__.'/lib/',
));
$loader->register();

//Load FogBugz API library
require_once 'vendor/kasperg/fogbugz-php-library/src/fogbugz_init.php';

$app = new BugYield\BugYield();
$app->run();