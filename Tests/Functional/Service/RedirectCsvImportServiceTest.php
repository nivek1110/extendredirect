<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\RedirectCsvExportService;
use Nivek1110\Extendredirect\Service\RedirectCsvImportService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RedirectCsvImportServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private RedirectCsvImportService $subject;
    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();
        // Importing a redirect triggers DataHandlerHook's immediate health check on
        // save; "database" mode keeps that check from firing a real frontend
        // sub-request, which the minimal test environment here has no TSFE for.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'database';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->subject = GeneralUtility::makeInstance(RedirectCsvImportService::class);
        $this->csvPath = $this->instancePath . '/typo3temp/var/tests/import.csv';
        @mkdir(dirname($this->csvPath), 0o777, true);

        $this->setUpBackendUserWithAdminRights();
    }

    protected function tearDown(): void
    {
        @unlink($this->csvPath);
        parent::tearDown();
    }

    private function setUpBackendUserWithAdminRights(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        $connection->insert('be_users', [
            'pid' => 0,
            'username' => 'admin',
            'password' => '',
            'admin' => 1,
        ]);
        $this->setUpBackendUser((int) $connection->lastInsertId());
    }

    private function insertRootPageWithSite(string $slug = '/'): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => 0,
            'title' => 'Root',
            'slug' => $slug,
            'is_siteroot' => 1,
            'doktype' => 1,
        ]);
        $rootUid = (int) $connection->lastInsertId();

        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', $rootUid, 'https://example.com/');

        return $rootUid;
    }

    private function insertPage(int $pid, string $slug): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'title' => 'Target page',
            'slug' => $slug,
            'doktype' => 1,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function writeCsv(string $content): void
    {
        file_put_contents($this->csvPath, $content);
    }

    public function testImportsAValidRowAndRewritesPageTargetToTypolink(): void
    {
        $rootUid = $this->insertRootPageWithSite();
        $targetPageUid = $this->insertPage($rootUid, '/target');

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/old-path;/target;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(1, $result['counter']);
        self::assertSame(1, $result['imported']);
        self::assertSame(0, $result['ignoreCounter']);

        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        self::assertCount(1, $redirects);
        self::assertSame('t3://page?uid=' . $targetPageUid, $redirects[0]['target']);
    }

    public function testUnknownSourceHostIsRejectedWithAnErrorMessage(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "unknown-host.example;/old-path;/target;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['ignoreCounter']);
        self::assertNotEmpty($result['messages']);
    }

    public function testDuplicateSourcePathIsSkipped(): void
    {
        $this->insertRootPageWithSite();
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'pid' => 0,
            'source_host' => 'example.com',
            'source_path' => '/already-exists',
            'target' => '/somewhere',
            'target_statuscode' => 301,
        ]);

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/already-exists;/target;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(0, $result['imported']);
        self::assertSame(1, $result['ignoreCounter']);
    }

    public function testInvalidStatusCodeFallsBackTo308(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/old-path;/somewhere-external;999\n"
        );

        $this->subject->importFromCsvFile($this->csvPath);

        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        self::assertSame(308, (int) $redirects[0]['target_statuscode']);
    }

    public function testMissingRequiredFieldAbortsImportWithAnErrorMessage(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path\n"
            . "example.com;/old-path\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(0, $result['imported']);
        self::assertNotEmpty($result['messages']);
    }

    private function insertSecondSite(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['pid' => 0, 'title' => 'Root 2', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        GeneralUtility::makeInstance(SiteWriter::class)
            ->createNewBasicSite('second', (int) $connection->lastInsertId(), 'https://other.example.org/');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importedRedirects(): array
    {
        return GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
    }

    public function testSameSourcePathOnDifferentHostsIsImportedForBoth(): void
    {
        $this->insertRootPageWithSite();
        $this->insertSecondSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/old;/a;301\n"
            . "other.example.org;/old;/b;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(2, $result['imported']);
        self::assertSame(0, $result['ignoreCounter']);
        self::assertCount(2, $this->importedRedirects());
    }

    public function testSameSourcePathTwiceOnSameHostSkipsTheSecondRow(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/old;/a;301\n"
            . "example.com;/old;/b;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['ignoreCounter']);
    }

    public function testWildcardHostRowBlocksTheSamePathOnASpecificHost(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "*;/old;/a;301\n"
            . "example.com;/old;/b;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['ignoreCounter']);
    }

    public function testOptionalBooleanColumnsAreEnabledByAnyValueAndSkippedWhenEmpty(): void
    {
        $this->insertRootPageWithSite();

        $this->writeCsv(
            "source_host;source_path;target;target_statuscode;force_https;keep_query_parameters\n"
            . "example.com;/old;/somewhere-external;301;1;\n"
        );

        $this->subject->importFromCsvFile($this->csvPath);

        $redirect = $this->importedRedirects()[0];
        self::assertSame(1, (int) $redirect['force_https']);
        self::assertSame(0, (int) $redirect['keep_query_parameters']);
    }

    public function testASourceHostThatIsALanguageDomainIsAccepted(): void
    {
        $this->insertRootPageWithSite();
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['pid' => 0, 'title' => 'Root 2', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->createNewBasicSite('second', (int) $connection->lastInsertId(), 'https://other.example.org/');
        $configuration = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier('second')->getConfiguration();
        $configuration['languages'][] = [
            'languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch',
            'locale' => 'de_DE.UTF-8', 'base' => 'https://de.other.example.org/', 'enabled' => true, 'flag' => 'de',
        ];
        $writer->write('second', $configuration);

        $this->writeCsv("source_host;source_path;target;target_statuscode\nde.other.example.org;/alt;/neu;301\n");
        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(1, $result['imported']);
        self::assertSame(0, $result['ignoreCounter']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRedirectRow(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', array_merge([
            'pid' => 0, 'source_host' => 'example.com', 'source_path' => '/old', 'target' => '/a',
            'target_statuscode' => 301, 'disabled' => 0, 'is_regexp' => 0,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function redirectRow(int $uid): array
    {
        $row = GeneralUtility::makeInstance(RedirectRepository::class)->findByUid($uid);
        self::assertIsArray($row);

        return $row;
    }

    public function testWithoutOverwriteAnExactDuplicateIsStillSkipped(): void
    {
        $this->insertRootPageWithSite();
        $uid = $this->insertRedirectRow();
        $this->writeCsv("source_host;source_path;target;target_statuscode\nexample.com;/old;/b;301\n");

        $result = $this->subject->importFromCsvFile($this->csvPath);

        self::assertSame(1, $result['ignoreCounter']);
        self::assertSame(0, $result['updated']);
        self::assertSame('/a', $this->redirectRow($uid)['target']);
    }

    public function testOverwriteUpdatesAnExactMatch(): void
    {
        $this->insertRootPageWithSite();
        $uid = $this->insertRedirectRow();
        $this->writeCsv("source_host;source_path;target;target_statuscode\nexample.com;/old;/b;308\n");

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(1, $result['updated']);
        self::assertSame(0, $result['imported']);
        self::assertSame(0, $result['unchanged']);
        $row = $this->redirectRow($uid);
        self::assertSame('/b', $row['target']);
        self::assertSame(308, (int) $row['target_statuscode']);
        self::assertCount(1, GeneralUtility::makeInstance(RedirectRepository::class)->findAll());
    }

    public function testOverwriteReportsAnIdenticalRowAsUnchangedAndDoesNotTouchIt(): void
    {
        $this->insertRootPageWithSite();
        $uid = $this->insertRedirectRow(['updatedon' => 111]);
        $this->writeCsv("source_host;source_path;target;target_statuscode\nexample.com;/old;/a;301\n");

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(1, $result['unchanged']);
        self::assertSame(0, $result['updated']);
        self::assertSame(111, (int) $this->redirectRow($uid)['updatedon'], 'nothing was written');
    }

    public function testOverwriteStillSkipsAFuzzyConflict(): void
    {
        $this->insertRootPageWithSite();
        $wildcard = $this->insertRedirectRow(['source_host' => '*', 'source_path' => '/old', 'target' => '/wild']);
        $this->writeCsv("source_host;source_path;target;target_statuscode\nexample.com;/old;/b;301\n");

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(1, $result['ignoreCounter'], 'a wildcard row is not "the same" redirect as one for a host');
        self::assertSame(0, $result['updated']);
        self::assertSame('/wild', $this->redirectRow($wildcard)['target']);
        self::assertCount(1, GeneralUtility::makeInstance(RedirectRepository::class)->findAll());
    }

    public function testEmptyCellsSwitchOptionsOffOnUpdateAndMissingColumnsStayUntouched(): void
    {
        $this->insertRootPageWithSite();
        $uid = $this->insertRedirectRow(['force_https' => 1, 'keep_query_parameters' => 1]);
        $this->writeCsv("source_host;source_path;target;target_statuscode;force_https\nexample.com;/old;/a;301;\n");

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(1, $result['updated']);
        $row = $this->redirectRow($uid);
        self::assertSame(0, (int) $row['force_https'], 'present column, empty cell: off');
        self::assertSame(1, (int) $row['keep_query_parameters'], 'column missing from the file: untouched');
    }

    public function testTheDisabledColumnEnablesAndDisablesOnUpdate(): void
    {
        $this->insertRootPageWithSite();
        $disabled = $this->insertRedirectRow(['source_path' => '/was-off', 'target' => 'https://elsewhere.example/x', 'disabled' => 1]);
        $enabled = $this->insertRedirectRow(['source_path' => '/was-on', 'target' => 'https://elsewhere.example/y']);
        $this->writeCsv(
            "source_host;source_path;target;target_statuscode;disabled\n"
            . "example.com;/was-off;https://elsewhere.example/x;301;\n"
            . "example.com;/was-on;https://elsewhere.example/y;301;1\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(2, $result['updated']);
        self::assertSame(0, (int) $this->redirectRow($disabled)['disabled']);
        self::assertSame(1, (int) $this->redirectRow($enabled)['disabled']);
    }

    public function testADuplicateRowInTheFileIsSkippedAndTheFirstOneWins(): void
    {
        $this->insertRootPageWithSite();
        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/dup;/first;301\n"
            . "example.com;/dup;/second;301\n"
        );

        $result = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertSame(1, $result['imported']);
        self::assertSame(1, $result['ignoreCounter']);
        $rows = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('/first', $rows[0]['target']);
    }

    private function mixedCsv(): void
    {
        $this->writeCsv(
            "source_host;source_path;target;target_statuscode\n"
            . "example.com;/new;/n;301\n"
            . "example.com;/change;/changed;301\n"
            . "example.com;/same;/s;301\n"
            . "example.com;/conflict;/c;301\n"
        );
    }

    public function testADryRunWritesNothingAndCountsLikeTheRealRun(): void
    {
        $this->insertRootPageWithSite();
        $change = $this->insertRedirectRow(['source_path' => '/change', 'target' => '/before']);
        $this->insertRedirectRow(['source_path' => '/same', 'target' => '/s']);
        $this->insertRedirectRow(['source_host' => '*', 'source_path' => '/conflict', 'target' => '/wild']);
        $this->mixedCsv();

        $dry = $this->subject->importFromCsvFile($this->csvPath, true, true);

        self::assertTrue($dry['dryRun']);
        self::assertSame([1, 1, 1, 1], [$dry['imported'], $dry['updated'], $dry['unchanged'], $dry['ignoreCounter']]);
        self::assertCount(3, GeneralUtility::makeInstance(RedirectRepository::class)->findAll(), 'nothing was created');
        self::assertSame('/before', $this->redirectRow($change)['target'], 'nothing was updated');
        self::assertSame(['imported', 'updated', 'unchanged', 'skipped'], array_column($dry['rows'], 'action'));
        self::assertSame(['target' => ['/before', '/changed']], $dry['rows'][1]['changes']);

        $real = $this->subject->importFromCsvFile($this->csvPath, true);

        self::assertFalse($real['dryRun']);
        self::assertSame([$dry['imported'], $dry['updated'], $dry['unchanged'], $dry['ignoreCounter']], [$real['imported'], $real['updated'], $real['unchanged'], $real['ignoreCounter']], 'the preview told the truth');
        self::assertSame([], $real['rows'], 'rows are only collected for a preview');
        self::assertSame('/changed', $this->redirectRow($change)['target']);
    }

    public function testADryRunWithoutOverwriteReportsExistingRowsAsSkipped(): void
    {
        $this->insertRootPageWithSite();
        $this->insertRedirectRow();
        $this->writeCsv("source_host;source_path;target;target_statuscode\nexample.com;/old;/b;301\nexample.com;/fresh;/f;301\n");

        $dry = $this->subject->importFromCsvFile($this->csvPath, false, true);

        self::assertSame([1, 0, 0, 1], [$dry['imported'], $dry['updated'], $dry['unchanged'], $dry['ignoreCounter']]);
        self::assertCount(1, GeneralUtility::makeInstance(RedirectRepository::class)->findAll());
    }

    public function testAnExportImportedAgainWithOverwriteChangesNothing(): void
    {
        $this->insertRootPageWithSite();
        $this->insertRedirectRow(['source_path' => '/one', 'target' => 'https://elsewhere.example/1', 'force_https' => 1]);
        $this->insertRedirectRow(['source_host' => '*', 'source_path' => '#^/two/(.*)$#', 'target' => 'https://elsewhere.example/$1', 'is_regexp' => 1, 'respect_query_parameters' => 1]);
        $this->insertRedirectRow(['source_path' => '/three', 'target' => 'https://elsewhere.example/3', 'disabled' => 1]);
        file_put_contents($this->csvPath, GeneralUtility::makeInstance(RedirectCsvExportService::class)->export());

        $result = $this->subject->importFromCsvFile($this->csvPath, true, true);

        self::assertSame(3, $result['unchanged'], 'exporting and importing again is a no-op');
        self::assertSame([0, 0, 0], [$result['imported'], $result['updated'], $result['ignoreCounter']]);
    }
}
