<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\RedirectCsvImportService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RedirectCsvImportServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
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
}
