<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Command;

use Nivek1110\Extendredirect\Command\CheckCommand;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CheckCommandTest extends TestCase
{
    public function testExecuteRunsHealthCheckAndReturnsSuccess(): void
    {
        $healthCheckService = $this->createMock(HealthCheckService::class);
        $healthCheckService->expects(self::once())->method('runHealthCheck');

        $command = new CheckCommand($healthCheckService);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
    }
}
