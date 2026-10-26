<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "request" check mode: only the frontend sub-request (redirectFollower()) is
 * replaced by canned PSR-7 responses, everything else - URL building, chain
 * following, status evaluation, persistence - runs for real.
 */
class HealthCheckServiceRequestModeTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private int $rootUid;
    private RedirectRepository $redirects;

    /** @var \ArrayObject<int, string> every URL the service requested */
    private \ArrayObject $requestedUrls;

    /** @var array<string, mixed> request-related globals as they were before the test, restored in tearDown() */
    private array $originalGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The testing framework does not reset these, and e.g. TYPO3 13.4's DataHandler reads
        // $GLOBALS['TYPO3_REQUEST'] - a request left behind here would break later test classes.
        foreach (['TYPO3_REQUEST', 'BE_USER'] as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $this->originalGlobals[$key] = $GLOBALS[$key];
            }
        }
        $this->requestedUrls = new \ArrayObject();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'request';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->rootUid = $this->insertPage(0, '/', true);
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', $this->rootUid, 'https://example.com/');
        $this->redirects = GeneralUtility::makeInstance(RedirectRepository::class);
    }

    protected function tearDown(): void
    {
        foreach (['TYPO3_REQUEST', 'BE_USER'] as $key) {
            if (array_key_exists($key, $this->originalGlobals)) {
                $GLOBALS[$key] = $this->originalGlobals[$key];
            } else {
                unset($GLOBALS[$key]);
            }
        }
        parent::tearDown();
    }

    private function insertPage(int $pid, string $slug, bool $siteRoot = false): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'title' => $slug,
            'slug' => $slug,
            'doktype' => 1,
            'is_siteroot' => (int) $siteRoot,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRedirect(string $sourcePath, string $target, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', array_merge([
            'pid' => 0,
            'source_host' => '*',
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => 301,
            'disabled' => 0,
            'is_regexp' => 0,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<string, ResponseInterface> $responses canned response per requested URL
     */
    private function createService(array $responses): HealthCheckService
    {
        return new class (
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            GeneralUtility::makeInstance(SiteFinder::class),
            $responses,
            $this->requestedUrls
        ) extends HealthCheckService {
            /**
             * @param array<string, ResponseInterface> $responses
             * @param \ArrayObject<int, string> $requestedUrls
             */
            public function __construct(
                ExtensionConfiguration $extensionConfiguration,
                SiteFinder $siteFinder,
                private readonly array $responses,
                private readonly \ArrayObject $requestedUrls
            ) {
                parent::__construct($extensionConfiguration, $siteFinder);
            }

            protected function redirectFollower(string $location): ResponseInterface
            {
                $this->requestedUrls->append($location);

                return $this->responses[$location] ?? new Response('php://temp', 404);
            }
        };
    }

    private function responseWithStatus(int $code): ResponseInterface
    {
        return new Response('php://temp', $code);
    }

    private function redirectTo(string $location): ResponseInterface
    {
        return new Response('php://temp', 301, ['Location' => $location]);
    }

    /**
     * @return array<string, mixed>
     */
    private function redirect(int $uid): array
    {
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);

        return $row;
    }

    public function testFollowedRedirectEndingInPageIsHealthyAndTargetRewritten(): void
    {
        $pageUid = $this->insertPage($this->rootUid, '/new');
        $uid = $this->insertRedirect('/old', '/new');
        $service = $this->createService([
            'https://example.com/old' => $this->redirectTo('https://example.com/new'),
            'https://example.com/new' => $this->responseWithStatus(200),
        ]);

        $service->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $pageUid, $row['target']);
        self::assertSame(1, (int) $row['redirect_count']);
    }

    public function testMultiHopChainIsReportedAsSingleHop(): void
    {
        $this->insertPage($this->rootUid, '/c');
        $uid = $this->insertRedirect('/a', '/b');
        $service = $this->createService([
            'https://example.com/a' => $this->redirectTo('https://example.com/b'),
            'https://example.com/b' => $this->redirectTo('https://example.com/c'),
            'https://example.com/c' => $this->responseWithStatus(200),
        ]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['redirect_count']);
        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
    }

    public function testNotFoundEndsInDisabledAndLogged(): void
    {
        $uid = $this->insertRedirect('/old', '/gone');
        $service = $this->createService([
            'https://example.com/old' => $this->redirectTo('https://example.com/gone'),
            'https://example.com/gone' => $this->responseWithStatus(404),
        ]);

        $service->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(1, (int) $row['disabled']);
        self::assertSame(1, (int) $row['broken_redirect']);
        $log = GeneralUtility::makeInstance(DisableLogRepository::class)->findAll();
        self::assertCount(1, $log);
        self::assertSame('The page was not found!', $log[0]['reason']);
    }

    public function testServerErrorDisablesRedirectWithStatusCodeInReason(): void
    {
        $uid = $this->insertRedirect('/old', '/boom');
        $service = $this->createService(['https://example.com/old' => $this->responseWithStatus(500)]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        $log = GeneralUtility::makeInstance(DisableLogRepository::class)->findAll();
        self::assertStringStartsWith('500 - ', $log[0]['reason']);
        self::assertSame('-', $log[0]['status_code']);
    }

    public function testEndlessRedirectLoopIsDisabledAsLoopRisk(): void
    {
        $uid = $this->insertRedirect('/loop', '/loop');
        $service = $this->createService([
            'https://example.com/loop' => $this->redirectTo('https://example.com/loop'),
        ]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame(10, (int) $this->redirect($uid)['redirect_count']);
    }

    public function testUnknownSourceHostIsDisabledWithoutAnyRequest(): void
    {
        $uid = $this->insertRedirect('/old', '/new', ['source_host' => 'unknown.example.net']);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertCount(0, $this->requestedUrls);
    }

    public function testWildcardHostMustResolveOnEveryConfiguredSite(): void
    {
        $secondRoot = $this->insertPage(0, '/', true);
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('second', $secondRoot, 'https://other.example.org/');
        $this->insertPage($this->rootUid, '/new');
        $uid = $this->insertRedirect('/old', '/new');
        $service = $this->createService([
            'https://example.com/old' => $this->responseWithStatus(200),
            'https://other.example.org/old' => $this->responseWithStatus(404),
        ]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        $log = GeneralUtility::makeInstance(DisableLogRepository::class)->findAll();
        self::assertStringContainsString('other.example.org', $log[0]['reason']);
        self::assertCount(2, $this->requestedUrls);
    }

    public function testRegexRedirectIsCheckedAgainstTargetPageNotSourceUrl(): void
    {
        $this->insertPage($this->rootUid, '/new');
        $healthy = $this->insertRedirect('#^/legacy$#', '/new', ['is_regexp' => 1]);
        $broken = $this->insertRedirect('#^/other$#', '/does-not-exist', ['is_regexp' => 1]);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($healthy)['disabled']);
        self::assertSame(1, (int) $this->redirect($broken)['disabled']);
        self::assertCount(0, $this->requestedUrls);
    }

    public function testDryRunDoesNotPersistRequestModeResult(): void
    {
        $uid = $this->insertRedirect('/old', '/gone');
        $service = $this->createService(['https://example.com/old' => $this->responseWithStatus(404)]);

        $result = $service->runBulkCheck(true);

        self::assertCount(1, $result['broken']);
        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
    }

    /**
     * The real sub-request runs the frontend middleware stack, which replaces the backend user,
     * the request global and the "backend.user" context aspect. The backend middleware that wraps
     * the editor's save request decides from exactly that aspect whether to delete the session
     * cookie - so it must come back untouched, or the editor is logged out right after saving.
     */
    public function testSubRequestLeavesTheEditorsBackendSessionStateUntouched(): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'username' => 'admin', 'admin' => 1];
        $originalRequest = new ServerRequest('https://example.com/typo3/record/edit', 'POST');
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('backend.user', new UserAspect($backendUser));
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
        $this->insertRedirect('/old', '/gone');

        $service = new class (
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            GeneralUtility::makeInstance(SiteFinder::class)
        ) extends HealthCheckService {
            protected function dispatchSubRequest(string $location): ResponseInterface
            {
                // What the frontend stack does to the surrounding backend request.
                $GLOBALS['BE_USER'] = null;
                $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://example.com/old', 'GET');
                GeneralUtility::makeInstance(Context::class)->setAspect('backend.user', new UserAspect());

                return new Response('php://temp', 404);
            }
        };

        $service->runBulkCheck();

        self::assertSame($backendUser, $GLOBALS['BE_USER']);
        self::assertSame($originalRequest, $GLOBALS['TYPO3_REQUEST']);
        self::assertTrue($context->getAspect('backend.user')->isLoggedIn());
    }

    public function testADisabledBrokenRedirectIsCheckedViaItsTargetAndHealedOnceItExists(): void
    {
        $pageUid = $this->insertPage($this->rootUid, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://example.com/new' => $this->responseWithStatus(200)]);

        $result = $service->recheck($uid);

        // The core ignores disabled redirects, so the source URL would 404 forever: the target is what counts.
        self::assertSame(HealthCheckService::RECHECK_HEALTHY, $result['status']);
        self::assertSame(['https://example.com/new'], $this->requestedUrls->getArrayCopy());
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame(0, (int) $row['broken_redirect']);
    }

    public function testADisabledBrokenRedirectWhoseTargetPathStill404sStaysDisabled(): void
    {
        $uid = $this->insertRedirect('/old', '/missing', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $service->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_BROKEN, $result['status']);
        self::assertSame(['https://example.com/missing'], $this->requestedUrls->getArrayCopy());
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['disabled']);
    }

    public function testADisabledBrokenRedirectToADeletedPageStaysDisabledWithoutARequest(): void
    {
        $uid = $this->insertRedirect('/old', 't3://page?uid=9999', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $service->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_BROKEN, $result['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledBrokenRedirectToAnExternalSiteIsLeftAloneAndHealed(): void
    {
        $uid = $this->insertRedirect('/old', 'https://other.example/page', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $service->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_HEALTHY, $result['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }
}
