<?php

use Symfony\Component\ClassLoader\UniversalClassLoader;

// Composer autoloader (PSR-4).
require 'vendor/autoload.php';

// Setup Symfony classloader and components (PSR-0).
// @TODO UniversalClassLoader is deprecated. Replace this.
$loader = new UniversalClassLoader();
$loader->registerNamespaces(array(
  'BugYield' => __DIR__.'/lib',
));
$loader->register();

//Load FogBugz API library
require_once 'vendor/kasperg/fogbugz-php-library/src/fogbugz_init.php';

$app = new BugYield\BugYield();
$app->run();