<?php

namespace BugYield;

use BugYield\Config;
use BugYield\Command\TitleSync;
use BugYield\Command\TimeSync;
use Symfony\Component\Yaml\Yaml;
use Silly\Edition\PhpDi\Application;

class BugYield extends Application
{

    public function __construct()
    {
        parent::__construct('Bug Yield', '1.0');

        $commonOptions = '[--harvest-project=] [--config=] [--bugtracker=] [--debug]';
        $commonOptionsDescs = [
            '--harvest-project' => 'One or more Harvest projects (id, name or code) separated by "," (comma). Use "all" for all projects',
            '--config' => 'Path to the configuration file',
            '--bugtracker' => 'Bug Tracker to yield',
            '--debug' => 'Show debug info',
        ];
        $commonOptionsDefaults = [
            'config' => 'config.yml',
            'bugtracker' => 'jira',
        ];

        // Directly registering the command class in the container and just
        // defining the command as the class is nicer to look at, but in order
        // to pass the config parameter to the Config class, we need to catch
        // it here.
        $this->command('timesync ' . $commonOptions, function ($input, $output) {
            // Add a definition for the Config class, adding the string
            // parameters, as autowiring only support automatically
            // determining parameters from classes, not simple types.
            // \DI\object is called \DI\autowire in later versions, at it
            // really autowires constructor parameters.
            $this->getContainer()->set(
                Config::class,
                \DI\object()->constructorParameter('configFile', $input->getOption('config'))
                ->constructorParameter('bugtracker', $input->getOption('bugtracker'))
            );

            // Invoke the command class much as silly would have done it.
            return $this->getInvoker()->call(TimeSync::class, [
                'input' => $input,
                'output' => $output,
            ]);
        }, ['tim', 'bugyield:timesync'])
            ->descriptions('Sync time registration from Harvest to bug tracker', $commonOptionsDescs)
            ->defaults($commonOptionsDefaults);

        $this->command('titlesync ' . $commonOptions, function ($input, $output) {
            $this->getContainer()->set(
                Config::class,
                \DI\object()->constructorParameter('configFile', $input->getOption('config'))
                ->constructorParameter('bugtracker', $input->getOption('bugtracker'))
            );

            return $this->getInvoker()->call(TitleSync::class, [
                'input' => $input,
                'output' => $output,
            ]);
        }, ['tit', 'bugyield:titlesync'])
            ->descriptions('Sync ticket titles from bug tracker to Harvest', $commonOptionsDescs)
            ->defaults($commonOptionsDefaults);
    }
}
