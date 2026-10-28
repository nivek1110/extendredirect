<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use Nivek1110\Extendredirect\Tests\Support\CreatesFiles;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
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
    use CreatesFiles;

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
        $this->removeCreatedPublicFiles();
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
        // Two redirects that lead into each other: not a plain self redirect, so only following it finds the loop.
        $uid = $this->insertRedirect('/loop-a', '/loop-b');
        $this->insertRedirect('/loop-b', '/loop-a');
        $service = $this->createService([
            'https://example.com/loop-a' => $this->redirectTo('https://example.com/loop-b'),
            'https://example.com/loop-b' => $this->redirectTo('https://example.com/loop-a'),
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

    /**
     * Runs the bulk check and reports whether the redirect ended up enabled ("healthy") or disabled ("broken").
     *
     * @return array{status: string}
     */
    private function check(HealthCheckService $service, int $uid): array
    {
        $service->runBulkCheck();
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);

        return ['status' => (int) $row['disabled'] === 0 ? 'healthy' : 'broken'];
    }

    public function testADisabledBrokenRedirectIsCheckedViaItsTargetAndHealedOnceItExists(): void
    {
        $pageUid = $this->insertPage($this->rootUid, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://example.com/new' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        // The core ignores disabled redirects, so the source URL would 404 forever: the target is what counts.
        self::assertSame('healthy', $result['status']);
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

        $result = $this->check($service, $uid);

        self::assertSame('broken', $result['status']);
        self::assertSame(['https://example.com/missing'], $this->requestedUrls->getArrayCopy());
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['disabled']);
    }

    public function testADisabledBrokenRedirectToADeletedPageStaysDisabledWithoutARequest(): void
    {
        $uid = $this->insertRedirect('/old', 't3://page?uid=9999', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $this->check($service, $uid);

        self::assertSame('broken', $result['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledBrokenRedirectToAnExternalSiteIsLeftAloneAndHealed(): void
    {
        $uid = $this->insertRedirect('/old', 'https://other.example/page', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    /**
     * @param array<string, mixed> $languageOverrides
     * @param array<string, mixed> $germanOverrides
     */
    private function createSecondSite(string $identifier, string $base, array $languageOverrides = [], bool $withGerman = false, string $germanBase = '/de/', array $germanOverrides = []): int
    {
        $rootUid = $this->insertPage(0, '/', true);
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->createNewBasicSite($identifier, $rootUid, $base);
        $configuration = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($identifier)->getConfiguration();
        $configuration['languages'][0] = array_merge($configuration['languages'][0], $languageOverrides);
        if ($withGerman) {
            $configuration['languages'][] = array_merge([
                'languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8', 'base' => $germanBase, 'enabled' => true, 'flag' => 'de',
            ], $germanOverrides);
        }
        $writer->write($identifier, $configuration);

        return $rootUid;
    }

    private function insertPageInSite(int $rootUid, string $slug, int $language = 0, int $l10nParent = 0): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $rootUid, 'title' => $slug, 'slug' => $slug, 'doktype' => 1,
            'sys_language_uid' => $language, 'l10n_parent' => $l10nParent,
        ]);

        return (int) $connection->lastInsertId();
    }

    public function testADisabledWildcardRedirectToAPageOfAnotherSiteIsRequestedOnThatSiteOnly(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/');
        $pageUid = $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/new' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame(['https://other.example/new'], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledRedirectToAPageIsRequestedWithTheLanguageBaseOfThePage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', ['base' => '/en/']);
        $pageUid = $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/en/new' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame(['https://other.example/en/new'], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledRedirectToATranslationIsRequestedInThatLanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $german = $this->insertPageInSite($otherRoot, '/neu', 1, $english);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $german, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/neu' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame(['https://other.example/de/neu'], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledWildcardRedirectToAnAbsoluteUrlOfAKnownSiteIsRequestedOnThatSiteOnly(): void
    {
        $this->createSecondSite('other', 'https://other.example/');
        $uid = $this->insertRedirect('/old', 'https://other.example/landing', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/landing' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame(['https://other.example/landing'], $this->requestedUrls->getArrayCopy());
    }

    public function testAnExceptionFromTheFrontendDoesNotAbortTheRunAndCountsAsAServerError(): void
    {
        $first = $this->insertRedirect('/old-a', '/x');
        $second = $this->insertRedirect('/old-b', '/y');
        $service = new class (
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            GeneralUtility::makeInstance(SiteFinder::class)
        ) extends HealthCheckService {
            protected function dispatchSubRequest(string $location): ResponseInterface
            {
                // e.g. a site without TypoScript: "No page configured for type=0"
                throw new \RuntimeException('No page configured for type=0.');
            }
        };

        $result = $service->runBulkCheck();

        self::assertSame(2, $result['checked'], 'one broken site must not stop the run');
        self::assertCount(2, $result['broken']);
        self::assertStringContainsString('500', $result['broken'][0]['message']);
        foreach ([$first, $second] as $uid) {
            $row = $this->redirects->findByUid($uid);
            self::assertIsArray($row);
            self::assertSame(1, (int) $row['disabled']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function languageParameterProvider(): array
    {
        return ['_language' => ['_language=1'], 'legacy L' => ['L=1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('languageParameterProvider')]
    public function testADisabledRedirectWithALanguageParameterIsRequestedInThatLanguage(string $parameter): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $this->insertPageInSite($otherRoot, '/neu', 1, $english);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&' . $parameter, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/neu' => $this->responseWithStatus(200)]);

        $result = $this->check($service, $uid);

        self::assertSame('healthy', $result['status']);
        self::assertSame(['https://other.example/de/neu'], $this->requestedUrls->getArrayCopy());
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);
        self::assertSame('t3://page?uid=' . $english . '&' . $parameter, $row['target'], 'the editor\'s link is kept as it is');
    }

    public function testADisabledRedirectToALanguageWithoutATranslationStaysBroken(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $result = $this->check($service, $uid);

        self::assertSame('broken', $result['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy(), 'no URL exists for the missing translation');
    }

    public function testASourceHostThatIsALanguageDomainIsRequestedThere(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true, 'https://de.other.example/');
        $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', '/new', ['source_host' => 'de.other.example']);
        $service = $this->createService([
            'https://de.other.example/old' => $this->redirectTo('https://de.other.example/new'),
            'https://de.other.example/new' => $this->responseWithStatus(200),
        ]);

        $service->runBulkCheck();

        // Before, only site bases were known hosts: this redirect was disabled as "unknown source host".
        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertContains('https://de.other.example/old', $this->requestedUrls->getArrayCopy());
        self::assertNotContains('https://other.example/old', $this->requestedUrls->getArrayCopy());
    }

    public function testAWildcardRedirectIsCheckedOnLanguageDomainsToo(): void
    {
        $this->createSecondSite('other', 'https://other.example/', [], true, 'https://de.other.example/');
        $this->insertRedirect('/old', '/new');
        $service = $this->createService([]);

        $service->runBulkCheck();

        $requested = $this->requestedUrls->getArrayCopy();
        self::assertContains('https://example.com/old', $requested);
        self::assertContains('https://other.example/old', $requested);
        self::assertContains('https://de.other.example/old', $requested, 'the wildcard also applies on the domain of a language');
    }

    public function testADisabledRedirectToAHiddenTranslationStaysBroken(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $german = $this->insertPageInSite($otherRoot, '/neu', 1, $english);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => $german]);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/neu' => $this->responseWithStatus(200)]);

        self::assertSame('broken', $this->check($service, $uid)['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledRedirectToADisabledLanguageStaysBroken(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true, '/de/', ['enabled' => false]);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $this->insertPageInSite($otherRoot, '/neu', 1, $english);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/neu' => $this->responseWithStatus(200)]);

        self::assertSame('broken', $this->check($service, $uid)['status']);
        self::assertSame([], $this->requestedUrls->getArrayCopy(), 'a disabled language has no frontend');
    }

    public function testAMissingTranslationIsFineWhenTheLanguageFallsBackToTheDefaultLanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true, '/de/', ['fallbackType' => 'fallback', 'fallbacks' => '0']);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/new' => $this->responseWithStatus(200)]);

        self::assertSame('healthy', $this->check($service, $uid)['status']);
        self::assertSame(['https://other.example/de/new'], $this->requestedUrls->getArrayCopy());
    }

    public function testAMissingTranslationIsBrokenInStrictAndFreeLanguageModes(): void
    {
        foreach (['strict', 'free'] as $mode) {
            $this->requestedUrls->exchangeArray([]);
            $otherRoot = $this->createSecondSite('other-' . $mode, 'https://' . $mode . '.example/', [], true, '/de/', ['fallbackType' => $mode]);
            $english = $this->insertPageInSite($otherRoot, '/new');
            $uid = $this->insertRedirect('/old-' . $mode, 't3://page?uid=' . $english . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
            $service = $this->createService([]);

            self::assertSame('broken', $this->check($service, $uid)['status'], $mode);
            self::assertSame([], array_filter($this->requestedUrls->getArrayCopy(), static fn(string $url): bool => str_contains($url, '/de/')), $mode);
        }
    }

    public function testADisabledRedirectToAPageForAllLanguagesIsRequestedInTheDefaultLanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $pageUid = $this->insertPageInSite($otherRoot, '/impressum', -1);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/impressum' => $this->responseWithStatus(200)]);

        self::assertSame('healthy', $this->check($service, $uid)['status']);
        self::assertSame(['https://other.example/impressum'], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledRedirectToAPageForAllLanguagesCanNameALanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $pageUid = $this->insertPageInSite($otherRoot, '/impressum', -1);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $pageUid . '&_language=1', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/impressum' => $this->responseWithStatus(200)]);

        self::assertSame('healthy', $this->check($service, $uid)['status']);
        self::assertSame(['https://other.example/de/impressum'], $this->requestedUrls->getArrayCopy());
    }

    public function testARewrittenTargetPointsAtThePageOfTheRequestedSiteNotAtTheSameSlugElsewhere(): void
    {
        $this->insertPage($this->rootUid, '/new'); // same slug on the main site
        $otherRoot = $this->createSecondSite('other', 'https://other.example/');
        $otherPage = $this->insertPageInSite($otherRoot, '/new');
        $uid = $this->insertRedirect('/old', '/new', ['source_host' => 'other.example']);
        $service = $this->createService([
            'https://other.example/old' => $this->redirectTo('https://other.example/new'),
            'https://other.example/new' => $this->responseWithStatus(200),
        ]);

        $service->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $otherPage, $row['target']);
    }

    public function testARewrittenTargetKeepsTheLanguageOfTheFinalUrl(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/new');
        $german = $this->insertPageInSite($otherRoot, '/neu', 1, $english);
        $uid = $this->insertRedirect('/old', '/de/neu', ['source_host' => 'other.example']);
        $service = $this->createService([
            'https://other.example/old' => $this->redirectTo('https://other.example/de/neu'),
            'https://other.example/de/neu' => $this->responseWithStatus(200),
        ]);

        $service->runBulkCheck();

        self::assertSame('t3://page?uid=' . $german, $this->redirect($uid)['target'], 'the translation, so the link stays German');
    }

    public function testARegexRedirectTargetWithALanguagePrefixIsFoundInThatLanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true);
        $english = $this->insertPageInSite($otherRoot, '/produkte');
        $this->insertPageInSite($otherRoot, '/produkte', 1, $english);
        $translated = $this->insertRedirect('#^/legacy$#', '/de/produkte', ['is_regexp' => 1, 'source_host' => 'other.example']);
        $untranslated = $this->insertRedirect('#^/legacy2$#', '/de/gibts-nicht', ['is_regexp' => 1, 'source_host' => 'other.example']);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($translated)['disabled']);
        self::assertSame(1, (int) $this->redirect($untranslated)['disabled']);
        self::assertCount(0, $this->requestedUrls);
    }

    public function testARegexRedirectToAHiddenPageOrAnExternalSite(): void
    {
        $page = $this->insertPage($this->rootUid, '/hidden-page');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => $page]);
        $toHidden = $this->insertRedirect('#^/a$#', 't3://page?uid=' . $page, ['is_regexp' => 1]);
        $toExternal = $this->insertRedirect('#^/b$#', 'https://elsewhere.example/page', ['is_regexp' => 1]);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($toHidden)['disabled'], 'a hidden page is not there for visitors');
        self::assertSame(0, (int) $this->redirect($toExternal)['disabled'], 'an external target cannot be checked and is left alone');
    }

    public function testADisabledRedirectToAPageThatOnlyExistsInOneLanguageIsRequestedInThatLanguage(): void
    {
        $otherRoot = $this->createSecondSite('other', 'https://other.example/', [], true, '/de/', ['fallbackType' => 'free']);
        $standalone = $this->insertPageInSite($otherRoot, '/nur-deutsch', 1);
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $standalone, ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService(['https://other.example/de/nur-deutsch' => $this->responseWithStatus(200)]);

        self::assertSame('healthy', $this->check($service, $uid)['status']);
        self::assertSame(['https://other.example/de/nur-deutsch'], $this->requestedUrls->getArrayCopy());
    }

    public function testARegexRedirectWithACaptureGroupInTheTargetIsNotCheckedButOneWithAConcreteTargetIs(): void
    {
        $dynamic = $this->insertRedirect('#^/a/(.*)$#', '/gone/$1', ['is_regexp' => 1]);
        $concreteBroken = $this->insertRedirect('#^/b$#', '/does-not-exist', ['is_regexp' => 1]);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($dynamic)['disabled'], 'a target with $1 cannot be verified: skipped');
        self::assertSame(1, (int) $this->redirect($concreteBroken)['disabled'], 'a concrete target of a regex redirect can');
        self::assertCount(0, $this->requestedUrls, 'the source is a pattern: never requested');
    }

    public function testAFollowedRedirectToAnExistingFileIsHealthyWithoutRequestingTheFile(): void
    {
        $this->createPublicFile('fileadmin/docs/test.pdf');
        $uid = $this->insertRedirect('/pdf', '/fileadmin/docs/test.pdf');
        // The in-process frontend request cannot serve static files, so the file must never be requested.
        $service = $this->createService(['https://example.com/pdf' => $this->redirectTo('https://example.com/fileadmin/docs/test.pdf')]);

        $service->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('/fileadmin/docs/test.pdf', $row['target']);
        self::assertSame(['https://example.com/pdf'], $this->requestedUrls->getArrayCopy());
    }

    public function testAFollowedRedirectToAMissingFileIsBroken(): void
    {
        $uid = $this->insertRedirect('/pdf', '/fileadmin/docs/gone.pdf');
        $service = $this->createService(['https://example.com/pdf' => $this->redirectTo('https://example.com/fileadmin/docs/gone.pdf')]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame(['https://example.com/pdf'], $this->requestedUrls->getArrayCopy());
    }

    public function testADisabledRedirectToAFileIsJudgedByTheFile(): void
    {
        $this->createPublicFile('fileadmin/docs/test.pdf');
        $existing = $this->insertRedirect('/a', '/fileadmin/docs/test.pdf', ['disabled' => 1, 'broken_redirect' => 1]);
        $missing = $this->insertRedirect('/b', '/fileadmin/docs/gone.pdf', ['disabled' => 1, 'broken_redirect' => 1]);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($existing)['disabled'], 'the file is back: healed');
        self::assertSame(1, (int) $this->redirect($missing)['disabled']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    public function testAFileLinkTargetIsJudgedByTheFileInRequestMode(): void
    {
        $existing = $this->createFalFile('linked.pdf');
        $disabledHealthy = $this->insertRedirect('/a', 't3://file?uid=' . $existing, ['disabled' => 1, 'broken_redirect' => 1]);
        $disabledUnknown = $this->insertRedirect('/b', 't3://file?uid=99999', ['disabled' => 1, 'broken_redirect' => 1]);
        $enabled = $this->insertRedirect('/c', 't3://file?uid=' . $existing);
        $service = $this->createService(['https://example.com/c' => $this->redirectTo('https://example.com/fileadmin/linked.pdf')]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($disabledHealthy)['disabled']);
        self::assertSame(1, (int) $this->redirect($disabledUnknown)['disabled']);
        self::assertSame(0, (int) $this->redirect($enabled)['disabled']);
        self::assertSame('t3://file?uid=' . $existing, $this->redirect($enabled)['target']);
    }

    public function testARegexRedirectWithAConcreteFileTargetIsJudgedByTheFile(): void
    {
        $this->createPublicFile('fileadmin/docs/test.pdf');
        $existing = $this->insertRedirect('#^/a$#', '/fileadmin/docs/test.pdf', ['is_regexp' => 1]);
        $missing = $this->insertRedirect('#^/b$#', '/fileadmin/docs/gone.pdf', ['is_regexp' => 1]);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($existing)['disabled']);
        self::assertSame(1, (int) $this->redirect($missing)['disabled']);
        self::assertSame([], $this->requestedUrls->getArrayCopy());
    }

    public function testARedirectToItselfIsDisabledWithoutRequestingAnything(): void
    {
        $uid = $this->insertRedirect('/loop', '/loop', ['source_host' => 'example.com']);
        $service = $this->createService([]);

        $service->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame([], $this->requestedUrls->getArrayCopy(), 'no ten requests to find out what is certain');
        $log = GeneralUtility::makeInstance(DisableLogRepository::class)->findAll();
        self::assertStringContainsString('points to itself', (string) $log[0]['reason']);
    }

    public function testARedirectThatHidesAPageIsAWarningInRequestModeToo(): void
    {
        $this->insertPage($this->rootUid, '/produkte');
        $this->insertPage($this->rootUid, '/kontakt');
        $uid = $this->insertRedirect('/produkte', '/kontakt', ['source_host' => 'example.com']);
        $service = $this->createService([
            'https://example.com/produkte' => $this->redirectTo('https://example.com/kontakt'),
            'https://example.com/kontakt' => $this->responseWithStatus(200),
        ]);

        $result = $service->runBulkCheck();

        self::assertSame([$uid], array_column($result['warnings'], 'uid'));
        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
    }

    public function testARedirectToAnotherHostIsLeftAloneInRequestMode(): void
    {
        $uid = $this->insertRedirect('/ext', 'https://elsewhere.example/page');
        $protocolRelative = $this->insertRedirect('/ext2', '//elsewhere.example/page');
        // Nothing is canned for the other host: requesting it would end in a 404 and a disabled redirect.
        $service = $this->createService([
            'https://example.com/ext' => $this->redirectTo('https://elsewhere.example/page'),
            'https://example.com/ext2' => $this->redirectTo('//elsewhere.example/page'),
        ]);

        $service->runBulkCheck();

        foreach ([$uid, $protocolRelative] as $redirectUid) {
            self::assertSame(0, (int) $this->redirect($redirectUid)['disabled'], 'a target on another host cannot be checked and is left alone');
        }
        self::assertSame('https://elsewhere.example/page', $this->redirect($uid)['target'], 'and not rewritten');
        self::assertSame(['https://example.com/ext', 'https://example.com/ext2'], $this->requestedUrls->getArrayCopy(), 'the other host is never requested');
    }

    public function testSubRequestLeavesTheBackendPageRendererAlone(): void
    {
        // The frontend rendering resets the shared PageRenderer, which would strip the backend module of its CSS
        // when the same request renders a page afterwards (the import renders after checking the imported rows).
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->setTitle('Backend module');
        $property = new \ReflectionProperty(PageRenderer::class, 'cssFiles');
        $property->setValue($pageRenderer, ['backend.css' => ['file' => 'backend.css']]);
        $this->insertRedirect('/old', '/gone');

        $service = new class (
            GeneralUtility::makeInstance(ExtensionConfiguration::class),
            GeneralUtility::makeInstance(SiteFinder::class)
        ) extends HealthCheckService {
            protected function dispatchSubRequest(string $location): ResponseInterface
            {
                $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
                $pageRenderer->setTitle('Frontend page');
                (new \ReflectionProperty(PageRenderer::class, 'cssFiles'))->setValue($pageRenderer, []);

                return new Response('php://temp', 404);
            }
        };

        $service->runBulkCheck();

        self::assertSame($pageRenderer, GeneralUtility::makeInstance(PageRenderer::class), 'the very same instance: the module template holds it');
        self::assertSame('Backend module', $pageRenderer->getTitle());
        self::assertSame(['backend.css' => ['file' => 'backend.css']], $property->getValue($pageRenderer));
    }
}
