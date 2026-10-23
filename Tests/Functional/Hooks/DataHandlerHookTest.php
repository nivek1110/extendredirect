<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Hooks;

use Nivek1110\Extendredirect\Hooks\DataHandlerHook;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class DataHandlerHookTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private RedirectRepository $redirectRepository;

    protected function setUp(): void
    {
        parent::setUp();
        // The hook runs an immediate health check on every sys_redirect save;
        // "database" mode keeps that from firing a real frontend sub-request,
        // which the minimal test environment here has no TSFE for.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'database';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);

        $this->setUpBackendUserWithAdminRights();
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

    private function insertRootPageWithSite(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => 0,
            'title' => 'Root',
            'slug' => '/',
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRedirect(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', array_merge([
            'pid' => 0,
            'source_host' => '*',
            'source_path' => '/foo',
            'target' => '/does-not-exist',
            'target_statuscode' => 301,
            'disabled' => 1,
            'broken_redirect' => 1,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    private function newDataHandler(): DataHandler
    {
        return GeneralUtility::makeInstance(DataHandler::class);
    }

    public function testUpdatingRedirectTargetReEnablesAndRechecksIt(): void
    {
        $rootUid = $this->insertRootPageWithSite();
        $targetPageUid = $this->insertPage($rootUid, '/target');
        $uid = $this->insertRedirect();

        $dataHandler = $this->newDataHandler();
        $dataHandler->start(['sys_redirect' => [$uid => ['target' => '/target']]], []);
        $dataHandler->process_datamap();

        $result = $this->redirectRepository->findByUid($uid);
        self::assertIsArray($result);
        self::assertSame(0, (int) $result['disabled']);
        self::assertSame(0, (int) $result['broken_redirect']);
        self::assertSame('t3://page?uid=' . $targetPageUid, $result['target']);
    }

    public function testCreatingNewRedirectRunsHealthCheckImmediately(): void
    {
        $rootUid = $this->insertRootPageWithSite();
        $this->insertPage($rootUid, '/target');

        $dataHandler = $this->newDataHandler();
        $dataHandler->start([
            'sys_redirect' => [
                'NEW1' => [
                    'pid' => 0,
                    'source_host' => '*',
                    'source_path' => '/new-source',
                    'target' => '/target',
                    'target_statuscode' => 301,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $newUid = (int) $dataHandler->substNEWwithIDs['NEW1'];
        $result = $this->redirectRepository->findByUid($newUid);
        self::assertIsArray($result);
        self::assertSame(0, (int) $result['broken_redirect']);
    }

    public function testHidingPageDisablesRedirectsTargetingIt(): void
    {
        $rootUid = $this->insertRootPageWithSite();
        $targetPageUid = $this->insertPage($rootUid, '/target');
        $uid = $this->insertRedirect(['target' => 't3://page?uid=' . $targetPageUid, 'disabled' => 0, 'broken_redirect' => 0]);

        $dataHandler = $this->newDataHandler();
        $dataHandler->start(['pages' => [$targetPageUid => ['hidden' => 1]]], []);
        $dataHandler->process_datamap();

        $result = $this->redirectRepository->findByUid($uid);
        self::assertIsArray($result);
        self::assertSame(1, (int) $result['disabled']);
    }

    public function testDeletingPageDisablesRedirectsTargetingIt(): void
    {
        $rootUid = $this->insertRootPageWithSite();
        $targetPageUid = $this->insertPage($rootUid, '/target');
        $uid = $this->insertRedirect(['target' => 't3://page?uid=' . $targetPageUid, 'disabled' => 0, 'broken_redirect' => 0]);

        $dataHandler = $this->newDataHandler();
        $dataHandler->start([], ['pages' => [$targetPageUid => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        $result = $this->redirectRepository->findByUid($uid);
        self::assertIsArray($result);
        self::assertSame(1, (int) $result['disabled']);
    }

    public function testNewRedirectWithoutResolvedUidSkipsTheHealthCheck(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);

        // No NEW... placeholder was ever substituted (e.g. the insert failed), so there is no uid to check.
        (new DataHandlerHook())->processDatamap_afterDatabaseOperations('new', 'sys_redirect', 'NEW123', [], $dataHandler);

        self::assertSame([], $this->redirectRepository->findAll());
    }
}
