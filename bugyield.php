<?php

use Symfony\Component\ClassLoader\UniversalClassLoader;

//Setup Symfony classloader and components
require_once __DIR__.'/vendor/Symfony/Component/ClassLoader/UniversalClassLoader.php';
$loader = new UniversalClassLoader();
$loader->registerNamespaces(array(
  'Symfony' => __DIR__.'/vendor',
  'BugYield' => __DIR__.'/lib',
));
$loader->register();

//Load FogBugz API library
require_once 'vendor/fogbugz-php-library/src/fogbugz_init.php';

//Load HaPi
require_once 'vendor/hapi/HarvestAPI.php';

spl_autoload_register( array('HarvestAPI', 'autoload') );

$app = new BugYield\BugYield();
$app->run();