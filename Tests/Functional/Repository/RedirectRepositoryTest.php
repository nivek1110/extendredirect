<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Repository;

use Nivek1110\Extendredirect\Repository\RedirectDemand;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class RedirectRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private RedirectRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(RedirectRepository::class);
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
            'is_regexp' => 0,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    public function testFindByUidReturnsRowRegardlessOfDisabledState(): void
    {
        $uid = $this->insertRedirect(['disabled' => 1]);

        $result = $this->subject->findByUid($uid);

        self::assertIsArray($result);
        self::assertSame($uid, (int) $result['uid']);
    }

    public function testFindByUidReturnsFalseForUnknownUid(): void
    {
        self::assertFalse($this->subject->findByUid(999999));
    }

    public function testFindRedirectsTargetingPageMatchesExactT3Link(): void
    {
        $matching = $this->insertRedirect(['target' => 't3://page?uid=5']);
        $this->insertRedirect(['target' => 't3://page?uid=6']);

        $result = $this->subject->findRedirectsTargetingPage('t3://page?uid=5', null);

        self::assertCount(1, $result);
        self::assertSame($matching, (int) $result[0]['uid']);
    }

    public function testFindRedirectsTargetingPageMatchesSlugSuffix(): void
    {
        $matching = $this->insertRedirect(['target' => 'https://example.com/some/page-slug']);

        $result = $this->subject->findRedirectsTargetingPage('t3://page?uid=999', '/page-slug');

        self::assertCount(1, $result);
        self::assertSame($matching, (int) $result[0]['uid']);
    }

    public function testDisableSetsDisabledAndBrokenRedirectFlags(): void
    {
        $uid = $this->insertRedirect();

        $this->subject->disable($uid);

        $result = $this->subject->findByUid($uid);
        self::assertIsArray($result);
        self::assertSame(1, (int) $result['disabled']);
        self::assertSame(1, (int) $result['broken_redirect']);
    }

    public function testExistsBySourcePath(): void
    {
        $this->insertRedirect(['source_path' => '/known-path']);

        self::assertTrue($this->subject->existsBySourcePath('/known-path'));
        self::assertFalse($this->subject->existsBySourcePath('/unknown-path'));
    }

    public function testFindAllIncludesDisabledRedirects(): void
    {
        $this->insertRedirect(['disabled' => 0]);
        $this->insertRedirect(['disabled' => 1]);

        self::assertCount(2, $this->subject->findAll());
    }

    public function testFindDuplicatesBySourcePathForSpecificHostMatchesSameHostAndWildcard(): void
    {
        $this->insertRedirect(['source_host' => 'example.com', 'source_path' => '/dup']);
        $this->insertRedirect(['source_host' => '*', 'source_path' => '/dup']);
        $this->insertRedirect(['source_host' => 'other.example.com', 'source_path' => '/dup']);

        $result = $this->subject->findDuplicatesBySourcePath('/dup', 'example.com');

        self::assertCount(2, $result);
    }

    public function testFindDuplicatesBySourcePathForWildcardHostMatchesEveryHost(): void
    {
        $this->insertRedirect(['source_host' => 'example.com', 'source_path' => '/dup']);
        $this->insertRedirect(['source_host' => 'other.example.com', 'source_path' => '/dup']);

        $result = $this->subject->findDuplicatesBySourcePath('/dup', '*');

        self::assertCount(2, $result);
    }

    public function testUpdateHealthCheckResultPersistsOutcome(): void
    {
        $uid = $this->insertRedirect();

        $this->subject->updateHealthCheckResult($uid, true, 3, 't3://page?uid=7');

        $result = $this->subject->findByUid($uid);
        self::assertIsArray($result);
        self::assertSame(1, (int) $result['broken_redirect']);
        self::assertSame('3', (string) $result['redirect_count']);
        self::assertSame(1, (int) $result['disabled']);
        self::assertSame('t3://page?uid=7', $result['target']);
    }

    public function testFindChainingRedirectMatchesFlatSourcePathOnSameOrWildcardHost(): void
    {
        $chain = $this->insertRedirect(['source_host' => '*', 'source_path' => '/chain-target', 'is_regexp' => 0]);
        $excludeUid = $this->insertRedirect(['source_path' => '/self']);

        $result = $this->subject->findChainingRedirect('/chain-target', 'example.com', $excludeUid);

        self::assertNotNull($result);
        self::assertSame($chain, (int) $result['uid']);
    }

    public function testFindChainingRedirectIgnoresDisabledCandidates(): void
    {
        $this->insertRedirect(['source_path' => '/chain-target', 'disabled' => 1]);
        $excludeUid = $this->insertRedirect(['source_path' => '/self']);

        self::assertNull($this->subject->findChainingRedirect('/chain-target', 'example.com', $excludeUid));
    }

    public function testFindChainingRedirectMatchesRegexpCandidate(): void
    {
        $chain = $this->insertRedirect(['source_path' => '#^/regex-(.*)$#', 'is_regexp' => 1]);
        $excludeUid = $this->insertRedirect(['source_path' => '/self']);

        $result = $this->subject->findChainingRedirect('/regex-anything', 'example.com', $excludeUid);

        self::assertNotNull($result);
        self::assertSame($chain, (int) $result['uid']);
    }

    public function testFindRedirectsByRbDemandFiltersBySourcePath(): void
    {
        $this->insertRedirect(['source_path' => '/match-me']);
        $this->insertRedirect(['source_path' => '/something-else']);

        $demand = new RedirectDemand(sourcePath: 'match-me');
        $result = $this->subject->findRedirectsByRbDemand($demand);

        self::assertCount(1, $result);
        self::assertSame('/match-me', $result[0]['source_path']);
    }

    public function testFindRedirectsByRbDemandFiltersByBrokenRedirect(): void
    {
        $this->insertRedirect(['broken_redirect' => 1]);
        $this->insertRedirect(['broken_redirect' => 0]);

        $demand = new RedirectDemand(brokenRedirect: 1);
        $result = $this->subject->findRedirectsByRbDemand($demand);

        self::assertCount(1, $result);
        self::assertSame(1, (int) $result[0]['broken_redirect']);
    }

    public function testUpdateHealthCheckResultStampsLastChecked(): void
    {
        $uid = $this->insertRedirect();
        $before = $this->subject->findByUid($uid);
        self::assertIsArray($before);
        self::assertSame(0, (int) $before['last_checked']);

        $this->subject->updateHealthCheckResult($uid, false, 0, '/bar');

        $after = $this->subject->findByUid($uid);
        self::assertIsArray($after);
        self::assertGreaterThan(0, (int) $after['last_checked']);
    }

    public function testFindAllReturnsNeverCheckedFirstThenOldestChecked(): void
    {
        $recent = $this->insertRedirect(['source_path' => '/recent', 'last_checked' => 2000]);
        $never = $this->insertRedirect(['source_path' => '/never', 'last_checked' => 0]);
        $old = $this->insertRedirect(['source_path' => '/old', 'last_checked' => 1000]);

        $uids = array_map(static fn(array $row): int => (int) $row['uid'], $this->subject->findAll());

        self::assertSame([$never, $old, $recent], $uids);
    }

    public function testExistsBySourcePathWithHostIgnoresOtherHosts(): void
    {
        $this->insertRedirect(['source_host' => 'a.example.com', 'source_path' => '/old']);

        self::assertTrue($this->subject->existsBySourcePath('/old', 'a.example.com'));
        self::assertFalse($this->subject->existsBySourcePath('/old', 'b.example.com'));
    }

    public function testExistsBySourcePathWithHostCountsWildcardRedirects(): void
    {
        $this->insertRedirect(['source_host' => '*', 'source_path' => '/old']);

        self::assertTrue($this->subject->existsBySourcePath('/old', 'a.example.com'));
    }

    public function testExistsBySourcePathForWildcardHostMatchesAnyHost(): void
    {
        $this->insertRedirect(['source_host' => 'a.example.com', 'source_path' => '/old']);

        self::assertTrue($this->subject->existsBySourcePath('/old', '*'));
    }
}
