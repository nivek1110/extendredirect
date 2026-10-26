<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\PageRedirectGuardService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The delete/hide protection must only disable redirects that really point at the page: the same path on
 * another site, in another language or the root slug "/" must not be hit.
 */
class PageRedirectGuardMultiSiteTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private PageRedirectGuardService $subject;
    private RedirectRepository $redirects;

    /** @var array<string, mixed> request-related globals as they were before the test, restored in tearDown() */
    private array $originalGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['TYPO3_REQUEST', 'BE_USER'] as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $this->originalGlobals[$key] = $GLOBALS[$key];
            }
        }
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->subject = GeneralUtility::makeInstance(PageRedirectGuardService::class);
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPage(int $pid, string $slug, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', array_merge(['pid' => $pid, 'title' => $slug, 'slug' => $slug, 'doktype' => 1], $overrides));

        return (int) $connection->lastInsertId();
    }

    /**
     * A site with its root page; $germanBase adds the language "Deutsch" (id 1) with that base.
     */
    private function createSite(string $identifier, string $base, ?string $germanBase = null): int
    {
        $rootUid = $this->insertPage(0, '/', ['title' => $identifier, 'is_siteroot' => 1]);
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->createNewBasicSite($identifier, $rootUid, $base);
        if ($germanBase !== null) {
            $configuration = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($identifier)->getConfiguration();
            $configuration['languages'][] = [
                'languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch',
                'locale' => 'de_DE.UTF-8', 'base' => $germanBase, 'enabled' => true, 'flag' => 'de',
            ];
            $writer->write($identifier, $configuration);
        }

        return $rootUid;
    }

    private function insertRedirect(string $target, string $sourceHost = '*'): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', [
            'pid' => 0, 'source_host' => $sourceHost, 'source_path' => '/from-' . $target, 'target' => $target,
            'target_statuscode' => 301, 'disabled' => 0,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function isDisabled(int $redirectUid): bool
    {
        $row = $this->redirects->findByUid($redirectUid);
        self::assertIsArray($row);

        return (int) $row['disabled'] === 1;
    }

    public function testTheRootPageSlugDoesNotDisableEveryRedirectWithATrailingSlash(): void
    {
        $root = $this->createSite('a', 'https://example.com/');
        $linked = $this->insertRedirect('t3://page?uid=' . $root);
        $other = $this->insertRedirect('/kontakt/');

        $this->subject->disableRedirectsTargetingPage($root, 'healthCheck.targetPageHidden');

        self::assertTrue($this->isDisabled($linked));
        self::assertFalse($this->isDisabled($other), 'only redirects that point at the root page are hit');
    }

    public function testTheSamePathOnAnotherSiteIsNotHit(): void
    {
        $rootA = $this->createSite('a', 'https://example.com/');
        $this->createSite('b', 'https://other.example/');
        $page = $this->insertPage($rootA, '/produkte/schuhe');
        $onA = $this->insertRedirect('/produkte/schuhe', 'example.com');
        $absoluteOnA = $this->insertRedirect('https://example.com/produkte/schuhe', '*');
        $onB = $this->insertRedirect('/produkte/schuhe', 'other.example');
        $absoluteOnB = $this->insertRedirect('https://other.example/produkte/schuhe', '*');

        $this->subject->disableRedirectsTargetingPage($page, 'healthCheck.targetPageDeleted');

        self::assertTrue($this->isDisabled($onA));
        self::assertTrue($this->isDisabled($absoluteOnA));
        self::assertFalse($this->isDisabled($onB));
        self::assertFalse($this->isDisabled($absoluteOnB));
    }

    public function testATranslationWithTheSameSlugAsTheDefaultPageOnlyHitsItsOwnLanguage(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $englishRedirect = $this->insertRedirect('/produkte/schuhe', 'example.com');
        $germanRedirect = $this->insertRedirect('/de/produkte/schuhe', 'example.com');

        $this->subject->disableRedirectsTargetingPage($german, 'healthCheck.targetPageHidden');

        self::assertTrue($this->isDisabled($germanRedirect));
        self::assertFalse($this->isDisabled($englishRedirect), 'the default language page still exists');
    }

    public function testADefaultPageDoesNotHitRedirectsToItsTranslation(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $englishRedirect = $this->insertRedirect('/produkte/schuhe', 'example.com');
        $germanRedirect = $this->insertRedirect('/de/produkte/schuhe', 'example.com');

        $this->subject->disableRedirectsTargetingPage($english, 'healthCheck.targetPageHidden');

        self::assertTrue($this->isDisabled($englishRedirect));
        self::assertFalse($this->isDisabled($germanRedirect));
    }

    public function testAPageForAllLanguagesHitsRedirectsInEveryLanguage(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $page = $this->insertPage($root, '/impressum', ['sys_language_uid' => -1]);
        $default = $this->insertRedirect('/impressum', 'example.com');
        $german = $this->insertRedirect('/de/impressum', 'example.com');

        $this->subject->disableRedirectsTargetingPage($page, 'healthCheck.targetPageDeleted');

        self::assertTrue($this->isDisabled($default));
        self::assertTrue($this->isDisabled($german));
    }

    public function testALanguageDomainIsMatchedByItsHost(): void
    {
        $root = $this->createSite('a', 'https://example.com/', 'https://de.example.com/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $onGermanDomain = $this->insertRedirect('https://de.example.com/produkte/schuhe');
        $onMainDomain = $this->insertRedirect('https://example.com/produkte/schuhe');

        $this->subject->disableRedirectsTargetingPage($german, 'healthCheck.targetPageHidden');

        self::assertTrue($this->isDisabled($onGermanDomain));
        self::assertFalse($this->isDisabled($onMainDomain));
    }

    public function testAPageLinkWithALanguageParameterIsHit(): void
    {
        $root = $this->createSite('a', 'https://example.com/');
        $page = $this->insertPage($root, '/produkte/schuhe');
        $withParameter = $this->insertRedirect('t3://page?uid=' . $page . '&_language=1');
        $otherPage = $this->insertRedirect('t3://page?uid=' . $page . '0');

        $this->subject->disableRedirectsTargetingPage($page, 'healthCheck.targetPageDeleted');

        self::assertTrue($this->isDisabled($withParameter));
        self::assertFalse($this->isDisabled($otherPage), 'uid 10 is not uid 1');
    }

    public function testDeletingADefaultPageThroughTheDataHandlerAlsoCoversItsTranslation(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe-de', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $toEnglish = $this->insertRedirect('t3://page?uid=' . $english);
        $toGerman = $this->insertRedirect('t3://page?uid=' . $german);
        $germanPath = $this->insertRedirect('/de/produkte/schuhe-de', 'example.com');

        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin', 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$english => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        self::assertTrue($this->isDisabled($toEnglish));
        self::assertTrue($this->isDisabled($toGerman), 'the translation is deleted with the page');
        self::assertTrue($this->isDisabled($germanPath));
    }

    public function testHidingADefaultPageAlsoCoversRedirectsToItsTranslations(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe-de', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $toEnglish = $this->insertRedirect('t3://page?uid=' . $english);
        $toGerman = $this->insertRedirect('t3://page?uid=' . $german);
        $germanPath = $this->insertRedirect('/de/produkte/schuhe-de', 'example.com');

        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin', 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$english => ['hidden' => 1]]], []);
        $dataHandler->process_datamap();

        self::assertTrue($this->isDisabled($toEnglish));
        self::assertTrue($this->isDisabled($toGerman), 'the translation of a hidden page is not reachable either');
        self::assertTrue($this->isDisabled($germanPath));
    }

    public function testHidingATranslationLeavesRedirectsToTheDefaultPageAlone(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);
        $toEnglish = $this->insertRedirect('t3://page?uid=' . $english);
        $toGerman = $this->insertRedirect('t3://page?uid=' . $german);

        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin', 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$german => ['hidden' => 1]]], []);
        $dataHandler->process_datamap();

        self::assertTrue($this->isDisabled($toGerman));
        self::assertFalse($this->isDisabled($toEnglish));
    }

    public function testRedirectsToTheRootPageByPathAreFoundWhenTheRootPageIsGone(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $german = $this->insertPage($root, '/', ['sys_language_uid' => 1, 'l10n_parent' => $root]);
        $byPath = $this->insertRedirect('/', 'example.com');
        $byUrl = $this->insertRedirect('https://example.com/');
        $germanByPath = $this->insertRedirect('/de/', 'example.com');
        $other = $this->insertRedirect('/kontakt/');
        $otherSite = $this->insertRedirect('https://other.example/');

        $this->subject->disableRedirectsTargetingPage($root, 'healthCheck.targetPageDeleted');

        self::assertTrue($this->isDisabled($byPath));
        self::assertTrue($this->isDisabled($byUrl));
        self::assertFalse($this->isDisabled($germanByPath), 'the German root page still exists');
        self::assertFalse($this->isDisabled($other));
        self::assertFalse($this->isDisabled($otherSite));
        self::assertGreaterThan(0, $german);
    }

    public function testRedirectsToTheGermanRootPageByPathAreFoundWhenOnlyItIsGone(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $german = $this->insertPage($root, '/', ['sys_language_uid' => 1, 'l10n_parent' => $root]);
        $englishByPath = $this->insertRedirect('/', 'example.com');
        $germanByPath = $this->insertRedirect('/de/', 'example.com');
        $germanNoSlash = $this->insertRedirect('/de');

        $this->subject->disableRedirectsTargetingPage($german, 'healthCheck.targetPageHidden');

        self::assertTrue($this->isDisabled($germanByPath));
        self::assertTrue($this->isDisabled($germanNoSlash));
        self::assertFalse($this->isDisabled($englishByPath));
    }

    private function loginAdmin(): void
    {
        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin' . uniqid(), 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());
    }

    public function testDeletingAPageAlsoCoversRedirectsToItsSubpages(): void
    {
        $root = $this->createSite('a', 'https://example.com/');
        $parent = $this->insertPage($root, '/produkte');
        $child = $this->insertPage($parent, '/produkte/schuhe');
        $grandchild = $this->insertPage($child, '/produkte/schuhe/sneaker');
        $toChild = $this->insertRedirect('t3://page?uid=' . $child);
        $toGrandchildByPath = $this->insertRedirect('/produkte/schuhe/sneaker', 'example.com');
        $elsewhere = $this->insertRedirect('/kontakt', 'example.com');

        $this->loginAdmin();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$parent => ['delete' => 1]]]);
        $dataHandler->process_cmdmap();

        self::assertTrue($this->isDisabled($toChild), 'the subpage is deleted with its parent');
        self::assertTrue($this->isDisabled($toGrandchildByPath));
        self::assertFalse($this->isDisabled($elsewhere));
        self::assertGreaterThan(0, $grandchild);
    }

    public function testHidingAPageAlsoCoversRedirectsToItsSubpagesAndTheirTranslations(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $parent = $this->insertPage($root, '/produkte');
        $child = $this->insertPage($parent, '/produkte/schuhe');
        $childGerman = $this->insertPage($parent, '/produkte/schuhe-de', ['sys_language_uid' => 1, 'l10n_parent' => $child]);
        $toChild = $this->insertRedirect('t3://page?uid=' . $child);
        $toChildGerman = $this->insertRedirect('t3://page?uid=' . $childGerman);
        $elsewhere = $this->insertRedirect('/kontakt', 'example.com');

        $this->loginAdmin();
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$parent => ['hidden' => 1]]], []);
        $dataHandler->process_datamap();

        self::assertTrue($this->isDisabled($toChild));
        self::assertTrue($this->isDisabled($toChildGerman));
        self::assertFalse($this->isDisabled($elsewhere));
    }

    public function testARegexRedirectWithAConcreteTargetIsDisabledAndOneWithACaptureGroupIsNotMatched(): void
    {
        $root = $this->createSite('a', 'https://example.com/');
        $page = $this->insertPage($root, '/neu');
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $insert = function (string $target) use ($connection): int {
            $connection->insert('sys_redirect', [
                'pid' => 0, 'source_host' => '*', 'source_path' => '#^/old/(.*)$#', 'target' => $target,
                'target_statuscode' => 301, 'disabled' => 0, 'is_regexp' => 1,
            ]);

            return (int) $connection->lastInsertId();
        };
        $concreteLink = $insert('t3://page?uid=' . $page);
        $concretePath = $insert('/neu');
        $dynamic = $insert('/neu/$1');

        $this->subject->disableRedirectsTargetingPage($page, 'healthCheck.targetPageDeleted');

        self::assertTrue($this->isDisabled($concreteLink));
        self::assertTrue($this->isDisabled($concretePath));
        self::assertFalse($this->isDisabled($dynamic), 'a capture group target is never matched');
    }
}
