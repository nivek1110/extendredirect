<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\RedirectCsvExportService;
use Nivek1110\Extendredirect\Service\RedirectCsvImportService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RedirectCsvExportServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'database';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->csvPath = $this->instancePath . '/typo3temp/var/tests/export-roundtrip.csv';
        @mkdir(dirname($this->csvPath), 0o777, true);

        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin', 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRedirect(array $overrides = []): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect', array_merge([
            'pid' => 0,
            'source_host' => '*',
            'source_path' => '/foo',
            'target' => 'https://other.example.org/bar',
            'target_statuscode' => 301,
            'disabled' => 0,
            'is_regexp' => 0,
        ], $overrides));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function redirectsByPath(): array
    {
        $rows = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        usort($rows, static fn(array $a, array $b): int => [$a['source_host'], $a['source_path']] <=> [$b['source_host'], $b['source_path']]);

        return array_map(static fn(array $row): array => [
            'source_host' => $row['source_host'],
            'source_path' => $row['source_path'],
            'target' => $row['target'],
            'target_statuscode' => (int) $row['target_statuscode'],
            'respect_query_parameters' => (int) $row['respect_query_parameters'],
            'keep_query_parameters' => (int) $row['keep_query_parameters'],
            'force_https' => (int) $row['force_https'],
            'is_regexp' => (int) $row['is_regexp'],
            'disabled' => (int) $row['disabled'],
        ], $rows);
    }

    public function testExportListsEveryRedirectIncludingDisabledOnesSortedByHostAndPath(): void
    {
        $this->insertRedirect(['source_host' => 'b.example.com', 'source_path' => '/z']);
        $this->insertRedirect(['source_host' => 'a.example.com', 'source_path' => '/y', 'disabled' => 1]);
        $this->insertRedirect(['source_host' => 'a.example.com', 'source_path' => '/x']);

        $csv = GeneralUtility::makeInstance(RedirectCsvExportService::class)->export();
        $lines = explode("\n", trim($csv));

        self::assertCount(4, $lines, 'header plus three redirects');
        self::assertStringStartsWith('a.example.com;/x;', $lines[1]);
        self::assertStringStartsWith('a.example.com;/y;', $lines[2]);
        self::assertStringEndsWith(';1', $lines[2], 'the disabled flag is exported');
        self::assertStringStartsWith('b.example.com;/z;', $lines[3]);
    }

    public function testAnExportCanBeImportedAgainWithoutLosingAnything(): void
    {
        $this->insertRedirect(['source_path' => '/plain', 'target_statuscode' => 302, 'force_https' => 1]);
        $this->insertRedirect(['source_path' => '#^/old/(\\d+)$#', 'target' => 'https://other.example.org/new/$1', 'is_regexp' => 1, 'respect_query_parameters' => 1]);
        $this->insertRedirect(['source_path' => '/parked', 'disabled' => 1, 'keep_query_parameters' => 1]);
        $this->insertRedirect(['source_path' => '/odd;"chars', 'target' => 'https://other.example.org/q?a=1;b="2"\\"x']);
        $before = $this->redirectsByPath();

        file_put_contents($this->csvPath, GeneralUtility::makeInstance(RedirectCsvExportService::class)->export());
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->truncate('sys_redirect');
        $result = GeneralUtility::makeInstance(RedirectCsvImportService::class)->importFromCsvFile($this->csvPath);

        self::assertSame(4, $result['imported'], json_encode($result['messages'], JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
        self::assertSame($before, $this->redirectsByPath());
    }
}
