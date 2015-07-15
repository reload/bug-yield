<?php

require 'vendor/autoload.php';

//Load FogBugz API library
require_once 'vendor/kasperg/fogbugz-php-library/src/fogbugz_init.php';

$app = new BugYield\BugYield();
$app->run();