<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Service\ReplacementSuggestionService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ReplacementSuggestionServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private ReplacementSuggestionService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(ReplacementSuggestionService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertPage(string $slug, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', array_merge(['pid' => 0, 'title' => $slug, 'slug' => $slug, 'doktype' => 1], $overrides));

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
        $entry = end($all);
        self::assertIsArray($entry);

        return $entry;
    }

    public function testOnlyLinkableDefaultLanguagePagesAreCandidates(): void
    {
        $standard = $this->insertPage('/shop');
        $this->insertPage('/folder', ['doktype' => 254]);
        $this->insertPage('/extern', ['doktype' => 3]);
        $this->insertPage('/gelöscht', ['deleted' => 1]);
        $this->insertPage('/uebersetzt', ['sys_language_uid' => 1]);
        $shortcut = $this->insertPage('/kurzbefehl', ['doktype' => 4]);

        $uids = array_column($this->subject->findLinkablePages(), 'uid');
        sort($uids);

        self::assertSame([$standard, $shortcut], $uids);
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
}
