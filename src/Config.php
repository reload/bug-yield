<?php

namespace BugYield;

use Exception;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

class Config
{
    protected $harvestConfig;
    protected $bugyieldConfig;
    protected $bugtrackerConfig;
    protected $bugtracker;

    public function __construct(string $configFile, string $bugtracker)
    {
        if (file_exists($configFile)) {
            $config = Yaml::parse($configFile);
            $this->harvestConfig = $config['harvest'];
            $this->bugyieldConfig = $config['bugyield'];
            if (isset($config[$bugtracker])) {
                $this->bugtrackerConfig = $config[$bugtracker];
            } else {
                throw new Exception(sprintf(
                    'Configuration file error: Unknown bugtracker label "%s"',
                    $bugtracker
                ));
            }
        } else {
            throw new Exception(sprintf('Missing configuration file %s', $configFile));
        }

        $this->bugtracker = $bugtracker;
    }

    public function harvest($key)
    {
        return $this->harvestConfig[$key] ?: null;
    }

    public function bugyield($key)
    {
        return $this->bugyieldConfig[$key] ?: null;
    }

    public function bugtracker($key)
    {
        return $this->bugtrackerConfig[$key] ?: null;
    }

    public function bugtrackerKey()
    {
        return $this->bugtracker;
    }

    public function bugtrackerConfig()
    {
        return $this->bugtrackerConfig;
    }
}
