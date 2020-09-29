<?php

namespace BugYield;

use RuntimeException;
use SendGrid;
use SendGrid\Mail\Mail;
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

    protected $sendGrid;

    public function __construct(Config $config, OutputInterface $output)
    {
        $this->config = $config;
        $this->output = $output;

        $this->sendGrid = new SendGrid(getenv('SENDGRID_API_KEY'));
    }

    public function mail($address, $name, $subject, $body, $notifyOnError = false)
    {
        if ($this->config->isDebug()) {
            $this->output->writeln("\n --- Mail debug ---");
            $this->output->writeln(print_r($subject), true);
            $this->output->writeln(print_r($body), true);
            $this->output->writeln(" --- EOM ---\n");
        }

        $email = new Mail();
        $email->setFrom($this->config->bugyield("email_from"));
        $email->setSubject($subject);
        $email->addTo($address, $name);
        $email->addContent("text/plain", $body);

        if ($notifyOnError && $this->config->getEmailNotifyOnError()) {
            $email->addCc($this->config->getEmailNotifyOnError());
        }

        if ($this->config->isDebug()) {
            $email->enableSandBoxMode();
        }

        $response = $this->sendGrid->send($email);

        $statusCode = $response->statusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException(sprintf(
                'Error from sendgrid code %s: %s',
                $response->statusCode(),
                $response->body()
            ));
        }
    }
}
