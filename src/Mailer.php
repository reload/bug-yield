<?php

namespace BugYield;

use Symfony\Component\Console\Output\OutputInterface;

class Mailer
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var OutputInterface
     */
    protected $output;

    public function __construct(Config $config, OutputInterface $output)
    {
        $this->config = $config;
        $this->output = $output;
    }

    public function mail($to, $subject, $body, $headers)
    {
        if ($this->config->isDebug()) {
            $this->output->writeln("\n --- Mail debug ---");
            $this->output->writeln(print_r($subject), true);
            $this->output->writeln(print_r($body), true);
            $this->output->writeln(" --- EOM ---\n");
        }
        return mail($to, $subject, $body, $headers);
    }
}
