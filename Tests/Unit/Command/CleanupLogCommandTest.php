<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Command;

use Nivek1110\Extendredirect\Command\CleanupLogCommand;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupLogCommandTest extends TestCase
{
    public function testExecuteDeletesEntriesOlderThanGivenDays(): void
    {
        $repository = $this->createMock(DisableLogRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static function (int $timestamp): bool {
                $expected = time() - 7 * 86400;

                return abs($timestamp - $expected) <= 5;
            }))
            ->willReturn(3);

        $tester = new CommandTester(new CleanupLogCommand($repository));
        $exitCode = $tester->execute(['--days' => '7']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function testExecuteDefaultsToThirtyDays(): void
    {
        $repository = $this->createMock(DisableLogRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static fn(int $timestamp): bool => abs($timestamp - (time() - 30 * 86400)) <= 5))
            ->willReturn(0);

        $tester = new CommandTester(new CleanupLogCommand($repository));

        self::assertSame(0, $tester->execute([]));
    }

    public function testExecuteRejectsInvalidDays(): void
    {
        $repository = $this->createMock(DisableLogRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');

        $tester = new CommandTester(new CleanupLogCommand($repository));

        self::assertSame(1, $tester->execute(['--days' => '0']));
    }
}
