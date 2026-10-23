<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Command;

use Nivek1110\Extendredirect\Command\CleanupLogCommand;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class CleanupLogCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private DisableLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = GeneralUtility::makeInstance(DisableLogRepository::class);
    }

    private function addEntry(int $redirectUid, int $ageInDays): void
    {
        $this->repository->add([
            'pid' => 0,
            'crdate' => time() - $ageInDays * 86400,
            'redirect_uid' => $redirectUid,
            'source_host' => 'example.com',
            'source_path' => '/foo',
            'target' => '/bar',
            'status_code' => '404',
            'reason' => 'not found',
        ]);
    }

    /**
     * @return list<int> redirect uids still in the log
     */
    private function remainingRedirectUids(): array
    {
        return array_values(array_map(
            static fn(array $row): int => (int) $row['redirect_uid'],
            $this->repository->findAll()
        ));
    }

    public function testDeletesOnlyEntriesOlderThanGivenDays(): void
    {
        $this->addEntry(1, 40);
        $this->addEntry(2, 31);
        $this->addEntry(3, 29);
        $this->addEntry(4, 1);

        $tester = new CommandTester(new CleanupLogCommand($this->repository));
        $exitCode = $tester->execute(['--days' => '30']);

        self::assertSame(0, $exitCode);
        self::assertSame([4, 3], $this->remainingRedirectUids());
        self::assertStringContainsString('Deleted 2', $tester->getDisplay());
    }

    public function testDefaultKeepsEntriesFromLastThirtyDays(): void
    {
        $this->addEntry(1, 45);
        $this->addEntry(2, 10);

        $tester = new CommandTester(new CleanupLogCommand($this->repository));
        $tester->execute([]);

        self::assertSame([2], $this->remainingRedirectUids());
    }

    public function testInvalidDaysLeavesLogUntouched(): void
    {
        $this->addEntry(1, 400);

        $tester = new CommandTester(new CleanupLogCommand($this->repository));

        self::assertSame(1, $tester->execute(['--days' => '0']));
        self::assertSame([1], $this->remainingRedirectUids());
    }

    public function testCommandIsRegisteredAndSchedulable(): void
    {
        $command = $this->get(CleanupLogCommand::class);

        self::assertInstanceOf(CleanupLogCommand::class, $command);
    }
}
