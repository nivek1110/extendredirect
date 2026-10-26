<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises the "database" check mode end to end: it never fires an HTTP
 * request, so the minimal functional environment is enough.
 */
class HealthCheckServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private HealthCheckService $subject;
    private RedirectRepository $redirects;
    private int $rootUid;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'database';
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $this->rootUid = (int) $pages->lastInsertId();
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', $this->rootUid, 'https://example.com/');

        $this->subject = GeneralUtility::makeInstance(HealthCheckService::class);
        $this->redirects = GeneralUtility::makeInstance(RedirectRepository::class);
    }

    private function insertPage(string $slug, int $hidden = 0): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $this->rootUid,
            'title' => $slug,
            'slug' => $slug,
            'doktype' => 1,
            'hidden' => $hidden,
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
     * @return array<string, mixed>
     */
    private function redirect(int $uid): array
    {
        $row = $this->redirects->findByUid($uid);
        self::assertIsArray($row);

        return $row;
    }

    public function testHealthyRedirectIsKeptAndTargetRewrittenToPageLink(): void
    {
        $pageUid = $this->insertPage('/new');
        $uid = $this->insertRedirect('/old', '/new');

        $this->subject->runHealthCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame(0, (int) $row['broken_redirect']);
        self::assertSame('t3://page?uid=' . $pageUid, $row['target']);
    }

    public function testRedirectToMissingPageIsDisabledAndLogged(): void
    {
        $uid = $this->insertRedirect('/old', '/does-not-exist');

        $this->subject->runHealthCheck();

        $row = $this->redirect($uid);
        self::assertSame(1, (int) $row['disabled']);
        self::assertSame(1, (int) $row['broken_redirect']);
        $log = GeneralUtility::makeInstance(DisableLogRepository::class)->findAll();
        self::assertCount(1, $log);
        self::assertSame($uid, (int) $log[0]['redirect_uid']);
        self::assertSame('/old', $log[0]['source_path']);
    }

    public function testRedirectToHiddenPageIsDisabled(): void
    {
        $this->insertPage('/hidden', 1);
        $uid = $this->insertRedirect('/old', '/hidden');

        $this->subject->runHealthCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testExternalTargetIsLeftAlone(): void
    {
        $uid = $this->insertRedirect('/old', 'https://other.example.org/somewhere');

        $this->subject->runHealthCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('https://other.example.org/somewhere', $row['target']);
    }

    public function testTargetWithCaptureGroupPlaceholderIsLeftAloneWhenTheCheckIsSwitchedOff(): void
    {
        $this->disableDynamicTargetCheck();
        $uid = $this->insertRedirect('#^/old/(.*)$#', '/new/$1', ['is_regexp' => 1]);

        $this->subject->runHealthCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('/new/$1', $row['target']);
    }

    public function testRedirectChainIsFlattenedToFinalPage(): void
    {
        $this->insertPage('/b');
        $finalUid = $this->insertPage('/c');
        $first = $this->insertRedirect('/a', '/b');
        $this->insertRedirect('/b', '/c');

        $this->subject->runHealthCheck($this->redirect($first));

        $row = $this->redirect($first);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $finalUid, $row['target']);
        self::assertSame(1, (int) $row['redirect_count']);
    }

    public function testChainLongerThanMaxHopsIsDisabledAsLoopRisk(): void
    {
        for ($i = 0; $i <= 13; $i++) {
            $this->insertPage('/p' . $i);
        }
        $first = $this->insertRedirect('/start', '/p0');
        for ($i = 0; $i < 13; $i++) {
            $this->insertRedirect('/p' . $i, '/p' . ($i + 1));
        }

        $this->subject->runHealthCheck($this->redirect($first));

        self::assertSame(1, (int) $this->redirect($first)['disabled']);
    }

    public function testChainResolvingOnlyAfterRedirectToMissingPageIsDisabled(): void
    {
        $this->insertPage('/b');
        $first = $this->insertRedirect('/a', '/b');
        $this->insertRedirect('/b', '/gone');

        $this->subject->runHealthCheck($this->redirect($first));

        self::assertSame(1, (int) $this->redirect($first)['disabled']);
    }

    public function testDuplicateSourcePathIsDisabled(): void
    {
        $this->insertPage('/new');
        $first = $this->insertRedirect('/old', '/new');
        $this->insertRedirect('/old', '/new');

        $this->subject->runHealthCheck($this->redirect($first));

        self::assertSame(1, (int) $this->redirect($first)['disabled']);
    }

    public function testBrokenRedirectIsHealedAndReenabledOnceTargetExists(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runHealthCheck();
        self::assertSame(1, (int) $this->redirect($uid)['disabled']);

        $this->insertPage('/later');
        $this->subject->runHealthCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame(0, (int) $row['broken_redirect']);
    }

    public function testBulkRunDoesNotReenableManuallyDisabledRedirect(): void
    {
        $this->insertPage('/new');
        $uid = $this->insertRedirect('/old', '/new', ['disabled' => 1]);

        $this->subject->runHealthCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testSavedRecordCheckDisablesBrokenRedirect(): void
    {
        $uid = $this->insertRedirect('/old', '/does-not-exist');

        $this->subject->runHealthCheck($this->redirect($uid));

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testDryRunReportsBrokenRedirectsWithoutChangingAnything(): void
    {
        $this->insertPage('/new');
        $healthy = $this->insertRedirect('/ok', '/new');
        $broken = $this->insertRedirect('/old', '/does-not-exist');

        $result = $this->subject->runBulkCheck(true);

        self::assertSame(2, $result['checked']);
        self::assertCount(1, $result['broken']);
        self::assertSame($broken, $result['broken'][0]['uid']);
        self::assertSame(0, (int) $this->redirect($broken)['disabled']);
        self::assertSame('/new', $this->redirect($healthy)['target']);
        self::assertSame(0, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll());
    }

    public function testBulkCheckSkipsManuallyDisabledRedirectsAndCountsThem(): void
    {
        $this->insertPage('/new');
        $this->insertRedirect('/old', '/new', ['disabled' => 1]);

        $result = $this->subject->runBulkCheck();

        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['skipped']);
    }

    public function testBulkCheckStopsWhenMaxRuntimeIsExceeded(): void
    {
        $this->insertPage('/new');
        $this->insertRedirect('/a', '/new');
        $this->insertRedirect('/b', '/new');
        $this->insertRedirect('/c', '/new');

        // 1.5s pause after the first check exceeds the 1s budget.
        $result = $this->subject->runBulkCheck(false, 1500, 1);

        self::assertSame(1, $result['checked']);
        self::assertSame(2, $result['remaining']);
    }

    public function testBulkCheckLimitProcessesOnlyThatManyAndRotatesOnNextRun(): void
    {
        $this->insertPage('/new');
        $first = $this->insertRedirect('/a', '/new');
        $second = $this->insertRedirect('/b', '/new');
        $third = $this->insertRedirect('/c', '/new');

        $run1 = $this->subject->runBulkCheck(false, 0, 0, 2);
        self::assertSame(2, $run1['checked']);
        self::assertSame(1, $run1['remaining']);
        self::assertSame(0, (int) $this->redirect($third)['last_checked']);

        $run2 = $this->subject->runBulkCheck(false, 0, 0, 2);
        self::assertSame(2, $run2['checked']);
        self::assertGreaterThan(0, (int) $this->redirect($third)['last_checked']);
        // The one checked in run 1 that is oldest is picked again before the newer one.
        self::assertGreaterThan(0, (int) $this->redirect($first)['last_checked']);
        self::assertGreaterThan(0, (int) $this->redirect($second)['last_checked']);
    }

    public function testDryRunDoesNotStampLastChecked(): void
    {
        $this->insertPage('/new');
        $uid = $this->insertRedirect('/a', '/new');

        $this->subject->runBulkCheck(true);

        self::assertSame(0, (int) $this->redirect($uid)['last_checked']);
    }

    public function testBulkCheckFlagsOnlyFirstTimeDisablingsAsNewlyDisabled(): void
    {
        $uid = $this->insertRedirect('/old', '/does-not-exist');

        $first = $this->subject->runBulkCheck();
        $second = $this->subject->runBulkCheck();

        self::assertSame([['uid' => $uid, 'newlyDisabled' => true]], $this->uidsAndFlag($first['broken']));
        self::assertSame([['uid' => $uid, 'newlyDisabled' => false]], $this->uidsAndFlag($second['broken']), 'still broken, but already disabled before this run');
    }

    public function testDryRunFlagsAnEnabledBrokenRedirectAsWouldBeNewlyDisabled(): void
    {
        $uid = $this->insertRedirect('/old', '/does-not-exist');

        $result = $this->subject->runBulkCheck(true);

        self::assertSame([['uid' => $uid, 'newlyDisabled' => true]], $this->uidsAndFlag($result['broken']));
    }

    public function testAStillBrokenRedirectStaysDisabledAndIsNotLoggedAgain(): void
    {
        $uid = $this->insertRedirect('/old', '/missing');
        $this->subject->runBulkCheck();
        $logged = GeneralUtility::makeInstance(DisableLogRepository::class)->countAll();

        $this->subject->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame($logged, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll());
    }

    /**
     * Replaces the core redirect index service with one that records which hosts get rebuilt (the next
     * makeInstance() hands it out).
     *
     * @return \ArrayObject<int, string>
     */
    private function spyOnRedirectIndexRebuilds(): \ArrayObject
    {
        $hosts = new \ArrayObject();
        GeneralUtility::addInstance(RedirectCacheService::class, new class ($hosts) extends RedirectCacheService {
            /** @param \ArrayObject<int, string> $hosts */
            public function __construct(private readonly \ArrayObject $hosts)
            {
                parent::__construct();
            }

            /** @return array<mixed> */
            public function rebuildForHost(string $sourceHost): array
            {
                $this->hosts->append($sourceHost);

                return [];
            }
        });

        return $hosts;
    }

    public function testHealingARedirectRebuildsTheCoreRedirectIndexSoItFiresAgain(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $this->insertPage('/later');
        $rebuilds = $this->spyOnRedirectIndexRebuilds();

        $this->subject->runBulkCheck();

        // The state is written with plain SQL; without a rebuild the frontend keeps its stale index, the
        // healed redirect never fires and the next check wrongly sees it as broken again.
        self::assertSame(['*'], $rebuilds->getArrayCopy());
    }

    public function testDisablingARedirectRebuildsTheCoreRedirectIndex(): void
    {
        $this->insertRedirect('/old', '/missing');
        $rebuilds = $this->spyOnRedirectIndexRebuilds();

        $this->subject->runBulkCheck();

        self::assertSame(['*'], $rebuilds->getArrayCopy());
    }

    public function testHealingARedirectKeepsItsDisableLogEntries(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->insertRedirect('/other', '/missing');
        $this->subject->runBulkCheck();
        $this->insertPage('/later');

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
        self::assertSame(2, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll(), 'the log is the history, nothing is deleted');
    }

    public function testARedirectThatBreaksAgainGetsANewLogEntry(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $pageUid = $this->insertPage('/later');
        $this->subject->runBulkCheck();
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => $pageUid]);

        $this->subject->runBulkCheck();

        $entries = array_filter(
            GeneralUtility::makeInstance(DisableLogRepository::class)->findAll(),
            static fn(array $entry): bool => (int) $entry['redirect_uid'] === $uid
        );
        self::assertCount(2, $entries);
    }

    public function testAHealedRedirectStaysEnabledOnTheNextRun(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $this->insertPage('/later');

        $this->subject->runBulkCheck();
        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
    }

    public function testASourceHostThatIsALanguageDomainIsKnownInDatabaseMode(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['pid' => 0, 'title' => 'Other', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $otherRoot = (int) $pages->lastInsertId();
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->createNewBasicSite('other', $otherRoot, 'https://other.example/');
        $configuration = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier('other')->getConfiguration();
        $configuration['languages'][] = [
            'languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch',
            'locale' => 'de_DE.UTF-8', 'base' => 'https://de.other.example/', 'enabled' => true, 'flag' => 'de',
        ];
        $writer->write('other', $configuration);
        $this->insertPage('/new'); // same slug in the main site, which must not be picked
        $pages->insert('pages', ['pid' => $otherRoot, 'title' => 'New', 'slug' => '/new', 'doktype' => 1]);
        $english = (int) $pages->lastInsertId();
        // the language domain shows German pages: the page needs a German translation to be there
        $pages->insert('pages', ['pid' => $otherRoot, 'title' => 'Neu', 'slug' => '/new', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => $english]);
        $pageUid = (int) $pages->lastInsertId();
        $uid = $this->insertRedirect('/old', '/new', ['source_host' => 'de.other.example']);

        $this->subject->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $pageUid, $row['target'], 'the page of the site that owns the language domain');
    }

    /**
     * A second site "other" (https://other.example/) with its root page and the language Deutsch (id 1).
     *
     * @param array<string, mixed> $germanOverrides
     */
    private function createMultilingualSite(string $germanBase = '/de/', array $germanOverrides = [], bool $withFrench = false): int
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['pid' => 0, 'title' => 'Other', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $root = (int) $pages->lastInsertId();
        $writer = GeneralUtility::makeInstance(SiteWriter::class);
        $writer->createNewBasicSite('other', $root, 'https://other.example/');
        $configuration = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier('other')->getConfiguration();
        $configuration['languages'][] = array_merge([
            'languageId' => 1, 'title' => 'Deutsch', 'navigationTitle' => 'Deutsch',
            'locale' => 'de_DE.UTF-8', 'base' => $germanBase, 'enabled' => true, 'flag' => 'de',
        ], $germanOverrides);
        if ($withFrench) {
            $configuration['languages'][] = [
                'languageId' => 2, 'title' => 'Français', 'navigationTitle' => 'Français',
                'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'enabled' => true, 'flag' => 'fr',
            ];
        }
        $writer->write('other', $configuration);

        return $root;
    }

    private function insertOtherPage(int $root, string $slug, int $language = 0, int $l10nParent = 0): int
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', [
            'pid' => $root, 'title' => $slug, 'slug' => $slug, 'doktype' => 1,
            'sys_language_uid' => $language, 'l10n_parent' => $l10nParent,
        ]);

        return (int) $pages->lastInsertId();
    }

    public function testDatabaseModeUnderstandsALanguagePrefixInTheTarget(): void
    {
        $root = $this->createMultilingualSite();
        $english = $this->insertOtherPage($root, '/produkte/schuhe');
        $german = $this->insertOtherPage($root, '/produkte/schuhe', 1, $english);
        $uid = $this->insertRedirect('/old', '/de/produkte/schuhe', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $german, $row['target'], 'the translation, not the default language page');
    }

    public function testDatabaseModeRejectsALanguagePrefixWithoutATranslation(): void
    {
        $root = $this->createMultilingualSite();
        $this->insertOtherPage($root, '/produkte/schuhe');
        $uid = $this->insertRedirect('/old', '/de/produkte/schuhe', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testDatabaseModeUnderstandsALanguageDomainAsSourceHost(): void
    {
        $root = $this->createMultilingualSite('https://de.other.example/');
        $english = $this->insertOtherPage($root, '/produkte/schuhe');
        $german = $this->insertOtherPage($root, '/produkte/schuhe-de', 1, $english);
        $uid = $this->insertRedirect('/old', '/produkte/schuhe-de', ['source_host' => 'de.other.example']);

        $this->subject->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $german, $row['target']);
    }

    public function testDatabaseModeResolvesAWildcardTargetWithALanguagePrefixOnAnySite(): void
    {
        $root = $this->createMultilingualSite();
        $english = $this->insertOtherPage($root, '/produkte/schuhe');
        $german = $this->insertOtherPage($root, '/produkte/schuhe-de', 1, $english);
        $uid = $this->insertRedirect('/old', '/de/produkte/schuhe-de');

        $this->subject->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $german, $row['target']);
    }

    public function testDatabaseModeAcceptsAPageForAllLanguagesInEveryLanguage(): void
    {
        $root = $this->createMultilingualSite();
        $this->insertOtherPage($root, '/impressum', -1);
        $plain = $this->insertRedirect('/old-a', '/impressum', ['source_host' => 'other.example']);
        $german = $this->insertRedirect('/old-b', '/de/impressum', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($plain)['disabled']);
        self::assertSame(0, (int) $this->redirect($german)['disabled']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}> overrides, translation exists, expected healthy
     */
    public static function languageParameterAvailabilityProvider(): array
    {
        // TYPO3 applies the fallback chain ("fallbacks") to pages in every fallbackType; the type only decides
        // what happens to content elements. Without a chain a missing translation means "page not found".
        return [
            'translation exists' => [[], true, true],
            'strict, no chain' => [['fallbackType' => 'strict'], false, false],
            'free, no chain' => [['fallbackType' => 'free'], false, false],
            'fallback to default' => [['fallbackType' => 'fallback', 'fallbacks' => '0'], false, true],
            'free with a chain to default' => [['fallbackType' => 'free', 'fallbacks' => '0'], false, true],
            'strict with a chain to default' => [['fallbackType' => 'strict', 'fallbacks' => '0'], false, true],
            'fallback to a language without the page' => [['fallbackType' => 'fallback', 'fallbacks' => '5'], false, false],
            'unknown type falls back to default' => [['fallbackType' => 'bogus'], false, true],
            'disabled language' => [['enabled' => false], true, false],
        ];
    }

    /**
     * @param array<string, mixed> $germanOverrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('languageParameterAvailabilityProvider')]
    public function testDatabaseModeChecksTheLanguageOfAPageLink(array $germanOverrides, bool $translationExists, bool $expectedHealthy): void
    {
        $root = $this->createMultilingualSite('/de/', $germanOverrides);
        $english = $this->insertOtherPage($root, '/new');
        if ($translationExists) {
            $this->insertOtherPage($root, '/neu', 1, $english);
        }
        $uid = $this->insertRedirect('/old', 't3://page?uid=' . $english . '&_language=1', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame($expectedHealthy ? 0 : 1, (int) $this->redirect($uid)['disabled']);
    }

    public function testAFallbackChainFindsTheTranslationInAnotherLanguage(): void
    {
        $root = $this->createMultilingualSite('/de/', ['fallbackType' => 'fallback', 'fallbacks' => '2'], true);
        $english = $this->insertOtherPage($root, '/new');
        $this->insertOtherPage($root, '/nouveau', 2, $english);
        $fromFrench = $this->insertRedirect('/old-a', 't3://page?uid=' . $english . '&_language=1', ['source_host' => 'other.example']);
        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($fromFrench)['disabled'], 'German falls back to the French translation');
    }

    public function testAFallbackChainWithoutAnyTranslationOrDefaultIsBroken(): void
    {
        $root = $this->createMultilingualSite('/de/', ['fallbackType' => 'fallback', 'fallbacks' => '2'], true);
        $english = $this->insertOtherPage($root, '/new');
        $uid = $this->insertRedirect('/old-a', 't3://page?uid=' . $english . '&_language=1', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testAPageThatOnlyExistsInOneLanguageIsFoundByItsPath(): void
    {
        $root = $this->createMultilingualSite('/de/', ['fallbackType' => 'free']);
        $standalone = $this->insertOtherPage($root, '/nur-deutsch', 1);
        $uid = $this->insertRedirect('/old', '/de/nur-deutsch', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $standalone, $row['target']);
    }

    public function testAChainThroughALanguagePrefixIsFlattenedToTheTranslationOfTheFinalPage(): void
    {
        $root = $this->createMultilingualSite();
        $englishB = $this->insertOtherPage($root, '/b');
        $this->insertOtherPage($root, '/b', 1, $englishB);
        $englishC = $this->insertOtherPage($root, '/c');
        $germanC = $this->insertOtherPage($root, '/c', 1, $englishC);
        // /old -> /de/b, and the page /de/b itself is the source of a second redirect to /de/c
        $first = $this->insertRedirect('/old', '/de/b', ['source_host' => 'other.example']);
        $this->insertRedirect('/de/b', '/de/c', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        $row = $this->redirect($first);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame('t3://page?uid=' . $germanC, $row['target'], 'the chain ends at the German page /de/c');
    }

    public function testAChainThatEndsInAMissingTranslationIsBroken(): void
    {
        $root = $this->createMultilingualSite();
        $englishB = $this->insertOtherPage($root, '/b');
        $this->insertOtherPage($root, '/b', 1, $englishB);
        $this->insertOtherPage($root, '/c'); // no German translation
        $first = $this->insertRedirect('/old', '/de/b', ['source_host' => 'other.example']);
        $this->insertRedirect('/de/b', '/de/c', ['source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($first)['disabled']);
    }

    private function disableDynamicTargetCheck(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkDynamicTargets'] = '0';
    }

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: bool}> target, slugs of existing pages, expected healthy
     */
    public static function dynamicTargetProvider(): array
    {
        return [
            'a page below the fixed part exists' => ['/new/$1', ['/new/a'], true],
            'the page of the fixed part itself exists' => ['/new/$1', ['/new'], true],
            'nothing below the fixed part' => ['/new/$1', ['/other/a'], false],
            'only a page that merely starts with the same letters' => ['/new/$1', ['/new-other'], false],
            'a partial last segment is not compared' => ['/new/product-$1', ['/new/a'], true],
            'no fixed part: nothing to compare' => ['/$1', [], true],
            'capture group right behind the host: nothing to compare' => ['https://other.example/$1', [], true],
            'a target on another host is left alone' => ['https://elsewhere.example/gone/$1', [], true],
            'deeper fixed part' => ['/a/b/$1', ['/a/b/c'], true],
            'deeper fixed part missing' => ['/a/b/$1', ['/a/x'], false],
        ];
    }

    /**
     * @param list<string> $slugs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dynamicTargetProvider')]
    public function testATargetWithACaptureGroupIsCheckedByItsFixedPart(string $target, array $slugs, bool $expectedHealthy): void
    {
        foreach ($slugs as $slug) {
            $this->insertPage($slug);
        }
        $uid = $this->insertRedirect('#^/old/(.*)$#', $target, ['is_regexp' => 1]);

        $this->subject->runBulkCheck();

        self::assertSame($expectedHealthy ? 0 : 1, (int) $this->redirect($uid)['disabled'], $target);
    }

    public function testATargetWithACaptureGroupIsLeftAloneWhenTheCheckIsSwitchedOff(): void
    {
        $this->disableDynamicTargetCheck();
        $uid = $this->insertRedirect('#^/old/(.*)$#', '/nothing-here/$1', ['is_regexp' => 1]);

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonPageTargetProvider(): array
    {
        return [
            'files' => ['/fileadmin/old/$1'],
            'uploads' => ['/uploads/$1'],
            'typo3temp' => ['/typo3temp/assets/$1'],
            'extension assets' => ['/_assets/$1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPageTargetProvider')]
    public function testATargetWithACaptureGroupPointingAtFilesIsNotAPageAndLeftAlone(string $target): void
    {
        $uid = $this->insertRedirect('#^/old/(.*)$#', $target, ['is_regexp' => 1]);

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($uid)['disabled'], $target);
    }

    public function testTheFixedPartOfADynamicTargetIsLookedUpInTheLanguageOfItsPrefix(): void
    {
        $root = $this->createMultilingualSite();
        $english = $this->insertOtherPage($root, '/neu/a');
        $this->insertOtherPage($root, '/neu/a', 1, $english);
        $this->insertOtherPage($root, '/only-english/a');
        $translated = $this->insertRedirect('#^/x/(.*)$#', '/de/neu/$1', ['is_regexp' => 1, 'source_host' => 'other.example']);
        $untranslated = $this->insertRedirect('#^/y/(.*)$#', '/de/only-english/$1', ['is_regexp' => 1, 'source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($translated)['disabled']);
        self::assertSame(1, (int) $this->redirect($untranslated)['disabled'], 'the section exists in English only');
    }

    public function testTheFixedPartOfADynamicTargetStaysInsideTheSiteOfTheSourceHost(): void
    {
        $this->insertPage('/new/a'); // main site only
        $root = $this->createMultilingualSite();
        $this->insertOtherPage($root, '/unrelated');
        $uid = $this->insertRedirect('#^/x/(.*)$#', '/new/$1', ['is_regexp' => 1, 'source_host' => 'other.example']);

        $this->subject->runBulkCheck();

        self::assertSame(1, (int) $this->redirect($uid)['disabled'], 'the section exists on the main site, not on other.example');
    }

    /**
     * @param list<array{uid: int, source: string, message: string, newlyDisabled: bool}> $broken
     * @return list<array{uid: int, newlyDisabled: bool}>
     */
    private function uidsAndFlag(array $broken): array
    {
        return array_map(static fn(array $b): array => ['uid' => $b['uid'], 'newlyDisabled' => $b['newlyDisabled']], $broken);
    }
}
