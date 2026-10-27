<?php

namespace Nivek1110\Extendredirect\Command;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Nivek1110\Extendredirect\Service\HealthCheckService;

class CheckCommand extends Command
{
    protected SymfonyStyle $io;

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure(): void
    {
        $this->setDescription('Check all redirects and disable broken ones');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report which redirects would be disabled, change nothing');
        $this->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Pause in milliseconds after each checked redirect (rate limit)', '0');
        $this->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Stop starting new checks after this many seconds (0 = no limit)', '0');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Check at most this many redirects per run, least recently checked first (0 = all)', '0');
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

        $options = [];
        foreach (['delay', 'max-runtime', 'limit'] as $name) {
            $value = filter_var($input->getOption($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) {
                // @extensionScannerIgnoreLine SymfonyStyle::error(), not the core API of the same name
                $this->io->error(sprintf('The "%s" option must be an integer of 0 or greater.', $name));

                return Command::FAILURE;
            }
            $options[$name] = $value;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->healthCheckService->runBulkCheck($dryRun, $options['delay'], $options['max-runtime'], $options['limit']);

        if ($dryRun) {
            $this->io->note('Dry run: nothing was changed.');
        }
        $newlyDisabled = 0;
        foreach ($result['broken'] as $broken) {
            if ($broken['newlyDisabled']) {
                $newlyDisabled++;
                $this->io->writeln(sprintf(
                    '%s redirect #%d (%s): %s',
                    $dryRun ? 'Would disable' : 'Disabled',
                    $broken['uid'],
                    $broken['source'],
                    $broken['message']
                ));
            } else {
                // Already disabled by an earlier check - nothing changed in this run.
                $this->io->writeln(sprintf(
                    'Still broken: redirect #%d (%s): %s',
                    $broken['uid'],
                    $broken['source'],
                    $broken['message']
                ));
            }
        }
        $warnings = $result['warnings'] ?? [];
        foreach ($warnings as $warning) {
            $this->io->writeln(sprintf(
                'Warning: redirect #%d (%s): %s',
                $warning['uid'],
                $warning['source'],
                $warning['message']
            ));
        }
        $this->io->success(sprintf(
            'Checked %d redirects, %d broken (%d %s), %d skipped (disabled by hand).',
            $result['checked'],
            count($result['broken']),
            $newlyDisabled,
            $dryRun ? 'would be newly disabled' : 'newly disabled',
            $result['skipped']
        ));
        if ($warnings !== []) {
            $this->io->note(sprintf('%d %s: enabled redirects that hide a published page. Nothing was disabled.', count($warnings), count($warnings) === 1 ? 'warning' : 'warnings'));
        }
        if ($result['remaining'] > 0) {
            $this->io->warning(sprintf('Run stopped early (limit or max runtime): %d redirects were not checked.', $result['remaining']));
        }

        return Command::SUCCESS;
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
