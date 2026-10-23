<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Command;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes disable log entries older than a given number of days, comparable
 * to the Scheduler's "Table garbage collection" task.
 */
class CleanupLogCommand extends Command
{
    private const DEFAULT_DAYS = 30;

    public function __construct(private readonly DisableLogRepository $disableLogRepository, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Delete disable log entries older than a given number of days');
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete log entries older than this many days',
            (string) self::DEFAULT_DAYS
        );
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $io->error('The "days" option must be an integer greater than 0.');

            return Command::FAILURE;
        }

        $deleted = $this->disableLogRepository->deleteOlderThan(time() - $days * 86400);
        $io->success(sprintf('Deleted %d disable log entries older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
