<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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

    public function testTargetWithCaptureGroupPlaceholderIsLeftAlone(): void
    {
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

    public function testRecheckReenablesABrokenRedirectOnceItsTargetExists(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        $this->insertPage('/later');

        $result = $this->subject->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_HEALTHY, $result['status']);
        $row = $this->redirect($uid);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame(0, (int) $row['broken_redirect']);
    }

    public function testRecheckKeepsAStillBrokenRedirectDisabledWithoutLoggingAgain(): void
    {
        $uid = $this->insertRedirect('/old', '/missing');
        $this->subject->runBulkCheck();
        $logged = GeneralUtility::makeInstance(DisableLogRepository::class)->countAll();

        $result = $this->subject->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_BROKEN, $result['status']);
        self::assertNotSame('', $result['message']);
        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame($logged, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll());
    }

    public function testRecheckRefusesARedirectThatWasDisabledByHand(): void
    {
        $this->insertPage('/new');
        $uid = $this->insertRedirect('/old', '/new', ['disabled' => 1]);

        $result = $this->subject->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_MANUALLY_DISABLED, $result['status']);
        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
    }

    public function testRecheckReportsAnUnknownRedirect(): void
    {
        self::assertSame(HealthCheckService::RECHECK_NOT_FOUND, $this->subject->recheck(9999)['status']);
    }

    public function testRecheckDisablesAndLogsAnEnabledRedirectThatBrokeMeanwhile(): void
    {
        $uid = $this->insertRedirect('/old', '/missing');

        $result = $this->subject->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_BROKEN, $result['status']);
        self::assertSame(1, (int) $this->redirect($uid)['disabled']);
        self::assertSame(1, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll());
    }

    public function testRetargetPointsABrokenRedirectAtTheChosenPageAndReenablesIt(): void
    {
        $uid = $this->insertRedirect('/old', '/missing');
        $this->subject->runBulkCheck();
        $pageUid = $this->insertPage('/replacement');

        $result = $this->subject->retarget($uid, $pageUid);

        self::assertSame(HealthCheckService::RECHECK_HEALTHY, $result['status']);
        $row = $this->redirect($uid);
        self::assertSame('t3://page?uid=' . $pageUid, $row['target']);
        self::assertSame(0, (int) $row['disabled']);
        self::assertSame(0, (int) $row['broken_redirect']);
    }

    public function testRetargetRefusesAMissingPageAndKeepsTheTarget(): void
    {
        $uid = $this->insertRedirect('/old', '/missing');
        $this->subject->runBulkCheck();

        $result = $this->subject->retarget($uid, 9999);

        self::assertSame(HealthCheckService::RETARGET_PAGE_NOT_FOUND, $result['status']);
        self::assertSame('/missing', $this->redirect($uid)['target']);
    }

    public function testRetargetRefusesARedirectDisabledByHand(): void
    {
        $pageUid = $this->insertPage('/replacement');
        $uid = $this->insertRedirect('/old', '/missing', ['disabled' => 1]);

        $result = $this->subject->retarget($uid, $pageUid);

        self::assertSame(HealthCheckService::RECHECK_MANUALLY_DISABLED, $result['status']);
        self::assertSame('/missing', $this->redirect($uid)['target']);
    }

    public function testRetargetReportsAnUnknownRedirect(): void
    {
        $pageUid = $this->insertPage('/replacement');

        self::assertSame(HealthCheckService::RECHECK_NOT_FOUND, $this->subject->retarget(9999, $pageUid)['status']);
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

        $this->subject->recheck($uid);

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

    /**
     * @return array<int, int> "repaired" timestamp per log entry, by redirect uid, oldest entry first
     */
    private function repairedByRedirect(): array
    {
        $result = [];
        foreach (array_reverse(GeneralUtility::makeInstance(DisableLogRepository::class)->findAll()) as $entry) {
            $result[(int) $entry['redirect_uid']] = (int) $entry['repaired'];
        }

        return $result;
    }

    public function testHealingARedirectKeepsItsDisableLogEntriesAndMarksThemRepaired(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $other = $this->insertRedirect('/other', '/missing');
        $this->subject->runBulkCheck();
        self::assertSame([$uid => 0, $other => 0], $this->repairedByRedirect());
        $this->insertPage('/later');

        $this->subject->recheck($uid);

        self::assertSame(2, GeneralUtility::makeInstance(DisableLogRepository::class)->countAll(), 'the log is the history, nothing is deleted');
        $repaired = $this->repairedByRedirect();
        self::assertGreaterThan(0, $repaired[$uid]);
        self::assertSame(0, $repaired[$other], 'only the healed redirect is flagged');
    }

    public function testABulkRunThatHealsARedirectAlsoMarksItsLogEntriesRepaired(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $this->insertPage('/later');

        $this->subject->runBulkCheck();

        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
        self::assertGreaterThan(0, $this->repairedByRedirect()[$uid]);
    }

    public function testARedirectThatBreaksAgainGetsANewOpenEntryNextToTheRepairedOne(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $pageUid = $this->insertPage('/later');
        $this->subject->runBulkCheck();
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => $pageUid]);

        $this->subject->runBulkCheck();

        $entries = array_values(array_filter(
            GeneralUtility::makeInstance(DisableLogRepository::class)->findAll(),
            static fn(array $entry): bool => (int) $entry['redirect_uid'] === $uid
        ));
        self::assertCount(2, $entries);
        self::assertSame(0, (int) $entries[0]['repaired'], 'newest entry is open');
        self::assertGreaterThan(0, (int) $entries[1]['repaired'], 'the old one stays repaired');
    }

    public function testARecheckOfAnAlreadyHealthyRedirectFlagsItsLeftoverEntryRepaired(): void
    {
        $this->insertPage('/new');
        $uid = $this->insertRedirect('/old', '/new');
        GeneralUtility::makeInstance(DisableLogRepository::class)->add([
            'pid' => 0, 'crdate' => 1000, 'redirect_uid' => $uid, 'source_host' => '*',
            'source_path' => '/old', 'target' => '/gone', 'status_code' => '-', 'reason' => 'Page not found',
        ]);

        $this->subject->recheck($uid);

        self::assertGreaterThan(0, $this->repairedByRedirect()[$uid]);
    }

    public function testASecondRecheckOfAHealedRedirectKeepsItEnabled(): void
    {
        $uid = $this->insertRedirect('/old', '/later');
        $this->subject->runBulkCheck();
        $this->insertPage('/later');

        $this->subject->recheck($uid);
        $second = $this->subject->recheck($uid);

        self::assertSame(HealthCheckService::RECHECK_HEALTHY, $second['status']);
        self::assertSame(0, (int) $this->redirect($uid)['disabled']);
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
