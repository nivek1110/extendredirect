<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Service\ReplacementSuggestionService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReplacementSuggestionServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private ReplacementSuggestionService $subject;
    private int $rootUid;

    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $this->rootUid = (int) $pages->lastInsertId();
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', $this->rootUid, 'https://example.com/');
        $this->subject = GeneralUtility::makeInstance(ReplacementSuggestionService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPage(string $slug, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', array_merge(['pid' => $this->rootUid, 'title' => $slug, 'slug' => $slug, 'doktype' => 1], $overrides));

        return (int) $connection->lastInsertId();
    }

    private function insertRedirect(string $target, int $disabled, int $broken): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', [
            'pid' => 0,
            'source_host' => '*',
            'source_path' => '/old-' . $target,
            'target' => $target,
            'target_statuscode' => 301,
            'disabled' => $disabled,
            'broken_redirect' => $broken,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return array<string, mixed> the stored log entry of the redirect
     */
    private function logFor(int $redirectUid): array
    {
        $logs = GeneralUtility::makeInstance(DisableLogRepository::class);
        $logs->add([
            'pid' => 0, 'crdate' => 1000, 'redirect_uid' => $redirectUid, 'source_host' => '*',
            'source_path' => '/x', 'target' => '/y', 'status_code' => '-', 'reason' => 'Page not found',
        ]);
        $all = $logs->findAll();
        $entry = reset($all);
        self::assertIsArray($entry);

        return $entry;
    }

    public function testOnlyVisibleLinkablePagesOfTheDefaultLanguageAreCandidates(): void
    {
        $standard = $this->insertPage('/shop');
        $this->insertPage('/shop-ordner', ['doktype' => 254]);
        $this->insertPage('/shop-extern', ['doktype' => 3]);
        $this->insertPage('/shop-geloescht', ['deleted' => 1]);
        $this->insertPage('/shop-versteckt', ['hidden' => 1]);
        $this->insertPage('/shop-uebersetzt', ['sys_language_uid' => 1]);
        $shortcut = $this->insertPage('/shop-kurzbefehl', ['doktype' => 4]);
        $entry = $this->logFor($this->insertRedirect('/shop-neu', 1, 1));

        $suggested = array_column($this->subject->suggestForLogEntries([$entry])[(int) $entry['uid']], 'uid');
        sort($suggested);

        self::assertSame([$standard, $shortcut], $suggested);
    }

    public function testBrokenRedirectsGetSuggestionsKeyedByLogEntry(): void
    {
        $shoes = $this->insertPage('/produkte/schuhe');
        $uid = $this->insertRedirect('/produkte/schuhe-neu', 1, 1);
        $entry = $this->logFor($uid);

        $result = $this->subject->suggestForLogEntries([$entry]);

        self::assertSame([(int) $entry['uid']], array_keys($result));
        self::assertSame($shoes, $result[(int) $entry['uid']][0]['uid']);
    }

    public function testRedirectsDisabledByHandHealthyOrGoneGetNoSuggestions(): void
    {
        $this->insertPage('/produkte/schuhe');
        $manual = $this->logFor($this->insertRedirect('/produkte/schuhe-neu', 1, 0));
        $healthy = $this->logFor($this->insertRedirect('/produkte/schuhe-neu', 0, 0));
        $gone = $this->logFor(9999);

        self::assertSame([], $this->subject->suggestForLogEntries([$manual, $healthy, $gone]));
    }

    public function testARepairedEntryGetsNoSuggestionsEvenIfTheRedirectBrokeAgain(): void
    {
        $this->insertPage('/produkte/schuhe');
        $uid = $this->insertRedirect('/produkte/schuhe-neu', 1, 1);
        $entry = $this->logFor($uid);
        $this->getConnectionPool()->getConnectionForTable('tx_extendredirect_disable_log')
            ->update('tx_extendredirect_disable_log', ['repaired' => 1700000000], ['uid' => $entry['uid']]);
        $entry['repaired'] = 1700000000;

        self::assertSame([], $this->subject->suggestForLogEntries([$entry]));
    }

    /**
     * @return list<array<string, mixed>> the suggestions of a redirect with the given target
     */
    private function suggestionsForTarget(string $target): array
    {
        $entry = $this->logFor($this->insertRedirect($target, 1, 1));

        return $this->subject->suggestForLogEntries([$entry])[(int) $entry['uid']] ?? [];
    }

    public function testAPageLinkToADeletedPageIsMatchedByItsFormerSlugAndTheDeletedPageIsNeverSuggested(): void
    {
        $deleted = $this->insertPage('/produkte/schuhe', ['deleted' => 1]);
        $live = $this->insertPage('/produkte/schuhe-sale');
        $this->insertPage('/kontakt');

        $suggestions = $this->suggestionsForTarget('t3://page?uid=' . $deleted);

        self::assertSame([$live], array_column($suggestions, 'uid'));
    }

    public function testAPageLinkToAHiddenPageDoesNotSuggestTheHiddenPage(): void
    {
        $hidden = $this->insertPage('/produkte/schuhe', ['hidden' => 1]);
        $live = $this->insertPage('/produkte/schuhe-neu');

        $suggestions = $this->suggestionsForTarget('t3://page?uid=' . $hidden);

        self::assertSame([$live], array_column($suggestions, 'uid'));
    }

    public function testAPageLinkToAPageThatIsGoneForGoodGetsNoSuggestions(): void
    {
        $this->insertPage('/produkte/schuhe');

        self::assertSame([], $this->suggestionsForTarget('t3://page?uid=9999'));
    }

    public function testAPageLinkToAFolderLikeTargetWithoutPathGetsNoSuggestions(): void
    {
        $this->insertPage('/produkte/schuhe');

        self::assertSame([], $this->suggestionsForTarget('t3://record?identifier=tt_news&uid=1'));
    }
}
