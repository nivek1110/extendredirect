<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Service\ReplacementSuggestionService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Suggestions must stay inside the site(s) and the language the broken redirect belongs to.
 */
class ReplacementSuggestionMultiSiteTest extends FunctionalTestCase
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
    private function insertPage(int $pid, string $slug, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', array_merge(['pid' => $pid, 'title' => $slug, 'slug' => $slug, 'doktype' => 1], $overrides));

        return (int) $connection->lastInsertId();
    }

    /**
     * Creates a site with its root page; $germanBase adds the language "Deutsch" (id 1) with that base.
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

    private function insertBrokenRedirect(string $sourceHost, string $target): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', [
            'pid' => 0, 'source_host' => $sourceHost, 'source_path' => '/old', 'target' => $target,
            'target_statuscode' => 301, 'disabled' => 1, 'broken_redirect' => 1,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function logFor(int $redirectUid): array
    {
        $logs = GeneralUtility::makeInstance(DisableLogRepository::class);
        $logs->add([
            'pid' => 0, 'crdate' => 1000, 'redirect_uid' => $redirectUid, 'source_host' => '*',
            'source_path' => '/old', 'target' => '/y', 'status_code' => '-', 'reason' => 'Page not found',
        ]);
        $all = $logs->findAll();
        $entry = reset($all);
        self::assertIsArray($entry);

        return $entry;
    }

    /**
     * @return list<array{uid: int, host: string}>
     */
    private function suggest(int $redirectUid): array
    {
        $entry = $this->logFor($redirectUid);

        return $this->subject->suggestForLogEntries([$entry])[(int) $entry['uid']] ?? [];
    }

    /**
     * @return list<int>
     */
    private function uids(int $redirectUid): array
    {
        $uids = array_map(static fn(array $s): int => $s['uid'], $this->suggest($redirectUid));
        sort($uids);

        return $uids;
    }

    public function testSuggestionsStayInsideTheSiteOfTheSourceHost(): void
    {
        $rootA = $this->createSite('a', 'https://example.com/');
        $rootB = $this->createSite('b', 'https://other.example/');
        $pageA = $this->insertPage($rootA, '/produkte/schuhe');
        $pageB = $this->insertPage($rootB, '/produkte/schuhe');

        self::assertSame([$pageA], $this->uids($this->insertBrokenRedirect('example.com', '/produkte/schuhe-neu')));
        self::assertSame([$pageB], $this->uids($this->insertBrokenRedirect('other.example', '/produkte/schuhe-neu')));
    }

    public function testAWildcardRedirectGetsSuggestionsFromEverySiteAndTheirHost(): void
    {
        $rootA = $this->createSite('a', 'https://example.com/');
        $rootB = $this->createSite('b', 'https://other.example/');
        $pageA = $this->insertPage($rootA, '/produkte/schuhe');
        $pageB = $this->insertPage($rootB, '/produkte/schuhe');

        $suggestions = $this->suggest($this->insertBrokenRedirect('*', '/produkte/schuhe-neu'));

        $hosts = [];
        foreach ($suggestions as $suggestion) {
            $hosts[$suggestion['uid']] = $suggestion['host'];
        }
        self::assertSame([$pageA => 'example.com', $pageB => 'other.example'], $hosts);
    }

    public function testALanguagePrefixOfTheTargetSelectsTheTranslation(): void
    {
        $root = $this->createSite('a', 'https://example.com/', '/de/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);

        self::assertSame([$german], $this->uids($this->insertBrokenRedirect('example.com', '/de/produkte/schuhe-neu')));
        self::assertSame([$english], $this->uids($this->insertBrokenRedirect('example.com', '/produkte/schuhe-neu')));
    }

    public function testALanguageHostSelectsTheTranslationAndFindsTheSite(): void
    {
        $root = $this->createSite('a', 'https://example.com/', 'https://de.example.com/');
        $english = $this->insertPage($root, '/produkte/schuhe');
        $german = $this->insertPage($root, '/produkte/schuhe', ['sys_language_uid' => 1, 'l10n_parent' => $english]);

        self::assertSame([$german], $this->uids($this->insertBrokenRedirect('de.example.com', '/produkte/schuhe-neu')));
    }

    public function testAnUnknownSourceHostGetsNoSuggestions(): void
    {
        $root = $this->createSite('a', 'https://example.com/');
        $this->insertPage($root, '/produkte/schuhe');

        self::assertSame([], $this->uids($this->insertBrokenRedirect('nowhere.example', '/produkte/schuhe-neu')));
    }
}
