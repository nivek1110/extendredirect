<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;

/**
 * Runs TYPO3's Extension Scanner (the one behind Install Tool -> Upgrade -> "Scan Extension Files")
 * on the extension with the TYPO3 version that is installed. A "strong" match means the extension
 * calls core API that was removed or changed - exactly what an integrator would see after an update.
 */
class ExtensionScannerTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $fixture = '';

    protected function setUp(): void
    {
        if (!is_dir(self::ROOT . '/.Build/vendor/typo3/cms-install')) {
            self::markTestSkipped('EXT:install (a dev dependency) is not installed.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== '') {
            foreach (glob($this->fixture . '/Classes/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->fixture . '/Classes');
            @rmdir($this->fixture);
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string} exit code and combined output
     */
    private function scan(array $arguments = []): array
    {
        $command = [PHP_BINARY, self::ROOT . '/Build/Scripts/extension-scan.php', ...array_values($arguments)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    private function fixture(string $code): string
    {
        $this->fixture = sys_get_temp_dir() . '/extendredirect-scan-' . uniqid('', true);
        mkdir($this->fixture . '/Classes', 0o777, true);
        file_put_contents($this->fixture . '/Classes/Subject.php', "<?php\n\n" . $code);

        return $this->fixture;
    }

    public function testTheExtensionHasNoStrongMatchesAndNoParseErrors(): void
    {
        [$exitCode, $output] = $this->scan();

        self::assertSame(0, $exitCode, $output);
        self::assertMatchesRegularExpression('/ 0 strong, \d+ weak, 0 parse errors/', $output);
    }

    public function testTheScanCoversTheExtensionsOwnCodeOnly(): void
    {
        [, $output] = $this->scan();

        self::assertSame(1, preg_match('/: (\d+) files,/', $output, $found), $output);
        $php = array_merge(
            glob(self::ROOT . '/Classes/*/*.php') ?: [],
            glob(self::ROOT . '/Configuration/*.php') ?: [],
            glob(self::ROOT . '/Configuration/*/*.php') ?: [],
            glob(self::ROOT . '/Configuration/*/*/*.php') ?: [],
            glob(self::ROOT . '/ext_*.php') ?: []
        );
        self::assertSame(count($php), (int) $found[1], 'only Classes/, Configuration/ and ext_*.php are scanned - not Tests/ or .Build/');
    }

    public function testRemovedCoreApiIsReportedAsStrongMatchAndFailsTheScan(): void
    {
        $path = $this->fixture("\$class = \\TYPO3\\CMS\\Backend\\Controller\\Wizard\\ColorpickerController::class;\n");

        [$exitCode, $output] = $this->scan(['--path=' . $path]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('STRONG Classes/Subject.php:3', $output);
        self::assertStringContainsString('ColorpickerController', $output);
    }

    public function testTheIgnoreLineAnnotationSilencesAMatch(): void
    {
        $path = $this->fixture(
            "// @extensionScannerIgnoreLine\n\$class = \\TYPO3\\CMS\\Backend\\Controller\\Wizard\\ColorpickerController::class;\n"
        );

        [$exitCode, $output] = $this->scan(['--path=' . $path]);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('No findings.', $output);
    }

    public function testWeakMatchesOnlyFailTheScanInStrictMode(): void
    {
        $path = $this->fixture("function check(\$thing): void\n{\n    \$thing->getLanguage();\n}\n");

        [$lenient, $lenientOutput] = $this->scan(['--path=' . $path]);
        [$strict] = $this->scan(['--path=' . $path, '--strict']);

        self::assertSame(0, $lenient, $lenientOutput);
        self::assertStringContainsString('WEAK Classes/Subject.php:5', $lenientOutput);
        self::assertSame(1, $strict);
    }

    public function testAMissingTypo3InstallationIsAUsageError(): void
    {
        [$exitCode, $output] = $this->scan(['--vendor=/nonexistent/vendor']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('No TYPO3 with EXT:install found', $output);
    }
}
