<?php

namespace TWT\TwtRedirect\Command;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TWT\TwtRedirect\Service\HealthCheckService;

class CheckCommand extends Command
{
    protected SymfonyStyle $io;

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure(): void
    {
        $this->setName('Check Redirects');
        $this->setDescription('Check Redirects and disable wrong Redirects');
    }

    /**
     * Constructor
     *
     * @param HealthCheckService $healthCheckService
     * @param null|string        $name
     */
    public function __construct(private readonly HealthCheckService $healthCheckService, ?string $name = null)
    {
        parent::__construct($name);
    }

    /**
     * Executes the command for showing sys_log entries
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int error code
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->healthCheckService->runHealthCheck();

        return 0;
    }

    /**
     * @param string $message
     * @param bool   $syslog
     *
     * @return void
     */
    protected function log(string $message, bool $syslog = false): void
    {
        if ($syslog === true) {
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $message, []);
        }
        $this->io->writeLn('<info>' . $message . '</info>');
    }

}
