<?php

namespace BugYield;

use BugYield\BugTracker\BugTracker;
use BugYield\BugTracker\BugTrackerBase;
use BugYield\Command\TimeSync;
use BugYield\Command\TitleSync;
use BugYield\Config;
use BugYield\TimeTracker\TimeTracker;
use BugYield\TimeTracker\TimeTrackerBase;
use Psr\Container\ContainerInterface;
use Silly\Edition\PhpDi\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

class BugYield extends Application
{

    public function __construct()
    {
        parent::__construct('Bug Yield', '1.0');

        // Directly registering the command class in the container and just
        // defining the command as the class is nicer to look at, but in order
        // to pass the config parameter to the Config class, we need to catch
        // it here.
        $this->command('timesync ' . Config::getOptions(), function ($input, $output) {
            // Add input to container for Config to get.
            $this->getContainer()->set(InputInterface::class, $input);

            // Define bugtracker. Consider letting Config load all bugtrackers
            // and have BugTrackerBase::getInstance pull out the config for
            // the selected one.
            $this->getContainer()->set(
                BugTracker::class,
                function (ContainerInterface $container) use ($input) {
                    return BugTrackerBase::getInstance($container->get(Config::class));
                }
            );

            // Define timetracker.
            $this->getContainer()->set(
                TimeTracker::class,
                function (ContainerInterface $container) use ($input) {
                    return TimeTrackerBase::getInstance($container->get(Config::class));
                }
            );

            // Invoke the command class much as silly would have done it.
            return $this->getInvoker()->call(TimeSync::class, [
                'input' => $input,
                'output' => $output,
            ]);
        }, ['tim', 'bugyield:timesync'])
            ->descriptions('Sync time registration from Harvest to bug tracker', Config::getOptionsDescriptions())
            ->defaults(Config::getOptionsDefaults());

        // Repeat for titlesync. Double work, but someday we'll refactor the
        // commands together to one command that does both jobs at once, with
        // switches to disable parts.
        $this->command('titlesync ' . Config::getOptions(), function ($input, $output) {
            $this->getContainer()->set(InputInterface::class, $input);

            $this->getContainer()->set(
                BugTracker::class,
                function (ContainerInterface $container) use ($input) {
                    return BugTrackerBase::getInstance($container->get(Config::class));
                }
            );

            $this->getContainer()->set(
                TimeTracker::class,
                function (ContainerInterface $container) use ($input) {
                    return TimeTrackerBase::getInstance($container->get(Config::class));
                }
            );

            return $this->getInvoker()->call(TitleSync::class, [
                'input' => $input,
                'output' => $output,
            ]);
        }, ['tit', 'bugyield:titlesync'])
            ->descriptions('Sync ticket titles from bug tracker to Harvest', Config::getOptionsDescriptions())
            ->defaults(Config::getOptionsDefaults());
    }
}
