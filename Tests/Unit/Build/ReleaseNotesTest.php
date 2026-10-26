<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;

/**
 * The release workflow publishes the CHANGELOG section of a tag as GitHub release text and
 * refuses tags whose version does not match the extension. These tests guard both inputs.
 */
class ReleaseNotesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private string $changelog;

    protected function setUp(): void
    {
        $this->changelog = sys_get_temp_dir() . '/extendredirect-changelog-' . uniqid('', true) . '.md';
        file_put_contents($this->changelog, <<<'MD'
            # Changelog

            ## [Unreleased]

            ## [1.2.0] - 2026-11-01

            ### Added
            - Newest feature.

            ## [1.1.0] - 2026-10-26

            Intro line.

            ### Added
            - Mail `notifyOnDisable`.

            ### Upgrade notes
            - None.

            ## [1.0.0] - 2026-10-23

            First release.
            MD);
    }

    protected function tearDown(): void
    {
        @unlink($this->changelog);
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function extract(string $version, ?string $file = null): array
    {
        $process = proc_open(
            ['bash', self::ROOT . '/Build/Scripts/release-notes.sh', $version, $file ?? $this->changelog],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testExtractsExactlyTheSectionOfTheGivenVersion(): void
    {
        [$code, $out] = $this->extract('1.1.0');

        self::assertSame(0, $code);
        self::assertStringContainsString('Intro line.', $out);
        self::assertStringContainsString('- Mail `notifyOnDisable`.', $out);
        self::assertStringContainsString('### Upgrade notes', $out);
        self::assertStringNotContainsString('Newest feature', $out, 'section above is excluded');
        self::assertStringNotContainsString('First release', $out, 'section below is excluded');
        self::assertStringNotContainsString('## [1.1.0]', $out, 'the heading itself becomes the release title, not text');
    }

    public function testLastSectionRunsToTheEndOfTheFile(): void
    {
        [$code, $out] = $this->extract('1.0.0');

        self::assertSame(0, $code);
        self::assertSame('First release.', trim($out));
    }

    public function testOutputHasNoLeadingOrTrailingBlankLines(): void
    {
        [, $out] = $this->extract('1.2.0');

        self::assertSame($out, trim($out) . "\n");
    }

    public function testUnknownVersionFailsWithAClearMessage(): void
    {
        [$code, $out, $err] = $this->extract('9.9.9');

        self::assertNotSame(0, $code);
        self::assertSame('', trim($out));
        self::assertStringContainsString('9.9.9', $err);
    }

    public function testAVersionIsNotMatchedAsAPrefixOfAnother(): void
    {
        file_put_contents($this->changelog, "## [1.10.0] - x\n\nTen.\n\n## [1.1.0] - y\n\nOne-one.\n");

        [$code, $out] = $this->extract('1.1.0');

        self::assertSame(0, $code);
        self::assertSame('One-one.', trim($out));
    }

    public function testExtensionVersionIsConsistentAndDocumentedInTheChangelog(): void
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = $composer['extra']['typo3/cms']['version'];

        $emconf = (string) file_get_contents(self::ROOT . '/ext_emconf.php');
        self::assertMatchesRegularExpression("/'version' => '" . preg_quote($version, '/') . "'/", $emconf, 'ext_emconf.php and composer.json must carry the same version');

        [$code, $out] = $this->extract($version, self::ROOT . '/CHANGELOG.md');
        self::assertSame(0, $code, 'CHANGELOG.md needs a section for the current version');
        self::assertNotSame('', trim($out));
    }
}
