<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\PageRedirectGuardService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class PageRedirectGuardServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private PageRedirectGuardService $subject;
    private RedirectRepository $redirectRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->subject = GeneralUtility::makeInstance(PageRedirectGuardService::class);
        $this->redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
    }

    private function insertPage(string $slug): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => 0,
            'title' => 'Test page',
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
            'source_host' => 'example.com',
            'source_path' => '/foo',
            'target' => '/bar',
            'target_statuscode' => 301,
            'disabled' => 0,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    public function testDisablesRedirectTargetingPageByT3Link(): void
    {
        $pageUid = $this->insertPage('/some-page');
        $redirectUid = $this->insertRedirect(['target' => 't3://page?uid=' . $pageUid]);

        $this->subject->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageHidden');

        $result = $this->redirectRepository->findByUid($redirectUid);
        self::assertSame(1, (int) $result['disabled']);
    }

    public function testDisablesRedirectTargetingPageBySlug(): void
    {
        $pageUid = $this->insertPage('/some-page');
        $redirectUid = $this->insertRedirect(['target' => 'https://example.com/some-page']);

        $this->subject->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageDeleted');

        $result = $this->redirectRepository->findByUid($redirectUid);
        self::assertSame(1, (int) $result['disabled']);
    }

    public function testWritesADisableLogEntryExplainingWhy(): void
    {
        $pageUid = $this->insertPage('/some-page');
        $this->insertRedirect(['target' => 't3://page?uid=' . $pageUid, 'source_path' => '/from']);

        $this->subject->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageHidden');

        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);
        $logs = $logRepository->findAll();

        self::assertCount(1, $logs);
        self::assertSame('/from', $logs[0]['source_path']);
        self::assertNotSame('', $logs[0]['reason']);
    }

    public function testDoesNotTouchAnAlreadyDisabledRedirect(): void
    {
        $pageUid = $this->insertPage('/some-page');
        $this->insertRedirect(['target' => 't3://page?uid=' . $pageUid, 'disabled' => 1]);

        $this->subject->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageHidden');

        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);
        self::assertSame(0, $logRepository->countAll());
    }

    public function testLeavesRedirectsTargetingOtherPagesUntouched(): void
    {
        $pageUid = $this->insertPage('/some-page');
        $otherPageUid = $pageUid + 100;
        $redirectUid = $this->insertRedirect(['target' => 't3://page?uid=' . $otherPageUid]);

        $this->subject->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageHidden');

        $result = $this->redirectRepository->findByUid($redirectUid);
        self::assertSame(0, (int) $result['disabled']);
    }
}
