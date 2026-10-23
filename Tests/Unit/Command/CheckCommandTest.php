<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Command;

use Nivek1110\Extendredirect\Command\CheckCommand;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CheckCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function bulkResult(array $overrides = []): array
    {
        return array_merge(['checked' => 0, 'skipped' => 0, 'remaining' => 0, 'broken' => []], $overrides);
    }

    public function testExecuteRunsBulkCheckWithDefaultsAndReturnsSuccess(): void
    {
        $healthCheckService = $this->createMock(HealthCheckService::class);
        $healthCheckService->expects(self::once())
            ->method('runBulkCheck')
            ->with(false, 0, 0, 0)
            ->willReturn($this->bulkResult(['checked' => 4]));

        $tester = new CommandTester(new CheckCommand($healthCheckService));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('4', $tester->getDisplay());
    }

    public function testExecutePassesDryRunDelayAndMaxRuntime(): void
    {
        $healthCheckService = $this->createMock(HealthCheckService::class);
        $healthCheckService->expects(self::once())
            ->method('runBulkCheck')
            ->with(true, 250, 60, 100)
            ->willReturn($this->bulkResult());

        $tester = new CommandTester(new CheckCommand($healthCheckService));

        self::assertSame(0, $tester->execute(['--dry-run' => true, '--delay' => '250', '--max-runtime' => '60', '--limit' => '100']));
    }

    public function testDryRunListsRedirectsThatWouldBeDisabled(): void
    {
        $healthCheckService = $this->createStub(HealthCheckService::class);
        $healthCheckService->method('runBulkCheck')->willReturn($this->bulkResult([
            'checked' => 1,
            'broken' => [['uid' => 7, 'source' => 'example.com/old', 'message' => 'The page was not found!']],
        ]));

        $tester = new CommandTester(new CheckCommand($healthCheckService));
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('example.com/old', $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
    }

    public function testExecuteWarnsWhenMaxRuntimeLeftRedirectsUnchecked(): void
    {
        $healthCheckService = $this->createStub(HealthCheckService::class);
        $healthCheckService->method('runBulkCheck')->willReturn($this->bulkResult(['checked' => 1, 'remaining' => 5]));

        $tester = new CommandTester(new CheckCommand($healthCheckService));
        $tester->execute(['--max-runtime' => '1']);

        self::assertStringContainsString('5', $tester->getDisplay());
    }

    public function testExecuteRejectsNegativeOptions(): void
    {
        $healthCheckService = $this->createMock(HealthCheckService::class);
        $healthCheckService->expects(self::never())->method('runBulkCheck');

        $tester = new CommandTester(new CheckCommand($healthCheckService));

        self::assertSame(1, $tester->execute(['--delay' => '-1']));
        self::assertSame(1, $tester->execute(['--max-runtime' => 'abc']));
        self::assertSame(1, $tester->execute(['--limit' => '-5']));
    }
}
