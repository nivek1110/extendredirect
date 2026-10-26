<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Proposes existing pages as new target for a broken redirect, judged by how similar their slug is
 * to the path the redirect pointed at. A suggestion is only a hint - it is never applied by itself.
 */
class ReplacementSuggestionService
{
    public const MIN_SCORE = 50;
    public const MAX_SUGGESTIONS = 3;

    /** Standard page, advanced page (legacy) and shortcut: the doktypes a redirect may sensibly point at. */
    private const LINKABLE_DOKTYPES = [1, 2, 4];

    /**
     * Ranks pages by similarity of their slug to the (broken) target, best first.
     *
     * The score (0-100) mixes the similarity of the whole path with that of the last segment, which
     * usually carries the meaning. A page that is an ancestor of the target path (/produkte for
     * /produkte/gone) scores at least MIN_SCORE, the deeper the better, so there is a fallback when
     * the leaf page is gone for good. The root page is never suggested.
     *
     * @param list<array{uid: int, title: string, slug: string}> $pages
     * @return list<array{uid: int, title: string, slug: string, score: int}>
     */
    public static function rank(string $target, array $pages): array
    {
        $targetPath = self::normalisePath($target);
        if ($targetPath === '') {
            return [];
        }
        $targetSegments = explode('/', $targetPath);

        $suggestions = [];
        foreach ($pages as $page) {
            $slugPath = self::normalisePath($page['slug']);
            if ($slugPath === '') {
                continue;
            }
            $slugSegments = explode('/', $slugPath);

            $whole = self::similarity($targetPath, $slugPath);
            $last = self::similarity((string) end($targetSegments), (string) end($slugSegments));
            $score = (int) round(0.4 * $whole + 0.6 * $last);

            if (count($slugSegments) < count($targetSegments)
                && array_slice($targetSegments, 0, count($slugSegments)) === $slugSegments) {
                $score = max($score, self::MIN_SCORE + (int) round(40 * count($slugSegments) / count($targetSegments)));
            }

            if ($score >= self::MIN_SCORE) {
                $suggestions[] = $page + ['score' => min(100, $score)];
            }
        }

        usort($suggestions, static fn(array $a, array $b): int => [$b['score'], strlen($a['slug'])] <=> [$a['score'], strlen($b['slug'])]);

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    public function __construct(private readonly SiteFinder $siteFinder) {}

    /**
     * Suggestions for the broken redirects among the given disable log entries.
     *
     * Repaired entries and those whose redirect is gone, healthy again or was disabled by hand get none.
     * The pages are loaded once for all entries. Suggestions stay inside the site(s) the redirect's source
     * host stands for (every site for "*") and inside the language of the target: a language prefix such as
     * /de/ - or a language of its own domain - selects the translated pages, otherwise the default language.
     *
     * @param array<int, array<string, mixed>> $logEntries
     * @return array<int, list<array{uid: int, title: string, slug: string, score: int, host: string, showHost: bool}>> by log entry uid
     */
    public function suggestForLogEntries(array $logEntries): array
    {
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class);
        $pages = null;
        $result = [];

        foreach ($logEntries as $entry) {
            if ((int) ($entry['repaired'] ?? 0) > 0) {
                continue;
            }
            $redirect = $redirects->findByUid((int) $entry['redirect_uid']);
            if (!is_array($redirect) || (int) $redirect['disabled'] !== 1 || (int) ($redirect['broken_redirect'] ?? 0) !== 1) {
                continue;
            }
            $pages ??= $this->loadPages();
            $result[(int) $entry['uid']] = $this->suggestFor((string) $redirect['source_host'], (string) $redirect['target'], $pages);
        }

        return $result;
    }

    /**
     * @param array{rows: list<array<string, mixed>>, parents: array<int, int>} $pages
     * @return list<array{uid: int, title: string, slug: string, score: int, host: string, showHost: bool}>
     */
    private function suggestFor(string $sourceHost, string $target, array $pages): array
    {
        // A page link has no path of its own: its page (deleted or hidden, that is why the redirect is broken)
        // tells what the redirect was looking for. That page is only the search key, it is never suggested.
        $pageTarget = null;
        if (str_starts_with($target, 't3://')) {
            $pageTarget = $this->resolvePageLinkTarget($target);
            if ($pageTarget === null) {
                return [];
            }
        }

        $scopes = $this->findScopes($sourceHost);
        $showHost = count($this->siteFinder->getAllSites()) > 1;
        $siteRootIds = array_values(array_map(static fn(Site $site): int => $site->getRootPageId(), $this->siteFinder->getAllSites()));

        $best = [];
        foreach ($scopes as $scope) {
            if ($pageTarget !== null) {
                // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
                $languageId = $pageTarget['language'] ?? $scope['language']?->getLanguageId() ?? $scope['site']->getDefaultLanguage()->getLanguageId();
                $path = $pageTarget['slug'];
            } else {
                [$languageId, $path] = $this->resolveLanguage($scope['site'], $scope['language'], $target);
            }
            $rootId = $scope['site']->getRootPageId();

            $candidates = [];
            foreach ($pages['rows'] as $row) {
                if ((int) $row['sys_language_uid'] !== $languageId
                    || (int) $row['hidden'] === 1
                    || (string) $row['slug'] === ''
                    || !in_array((int) $row['doktype'], self::LINKABLE_DOKTYPES, true)
                    || $this->findSiteRootId($this->defaultLanguageUid($row), $pages['parents'], $siteRootIds) !== $rootId) {
                    continue;
                }
                $candidates[] = ['uid' => (int) $row['uid'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']];
            }

            foreach (self::rank($path, $candidates) as $suggestion) {
                $suggestion += ['host' => $scope['site']->getBase()->getHost(), 'showHost' => $showHost];
                if (!isset($best[$suggestion['uid']]) || $best[$suggestion['uid']]['score'] < $suggestion['score']) {
                    $best[$suggestion['uid']] = $suggestion;
                }
            }
        }

        $suggestions = array_values($best);
        usort($suggestions, static fn(array $a, array $b): int => [$b['score'], strlen($a['slug'])] <=> [$a['score'], strlen($b['slug'])]);

        return array_slice($suggestions, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * The slug and language of the page a page link points at, even if it is deleted or hidden. Null if the
     * link is no page link or its page is gone for good (hard-deleted): then there is nothing to compare.
     *
     * @return array{slug: string, language: ?int}|null
     */
    private function resolvePageLinkTarget(string $target): ?array
    {
        try {
            $link = GeneralUtility::makeInstance(LinkService::class)->resolve($target);
        } catch (\Throwable) {
            return null;
        }
        if (($link['type'] ?? '') !== LinkService::TYPE_PAGE) {
            return null;
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('slug', 'sys_language_uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter((int) ($link['pageuid'] ?? 0), ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false || (string) $row['slug'] === '') {
            return null;
        }

        // A translation row knows its language; for a default language page "&_language=1" (or "&L=1") says it.
        parse_str((string) parse_url($target, PHP_URL_QUERY), $parameters);
        $parameter = $parameters['_language'] ?? $parameters['L'] ?? null;
        $language = (int) $row['sys_language_uid'] > 0
            ? (int) $row['sys_language_uid']
            : (is_numeric($parameter) ? (int) $parameter : null);

        return ['slug' => (string) $row['slug'], 'language' => $language];
    }

    /**
     * The sites a source host stands for, each with the language the host itself implies (a language with a
     * domain of its own), if any. "*" and an empty host mean every site.
     *
     * @return list<array{site: Site, language: ?SiteLanguage}>
     */
    private function findScopes(string $sourceHost): array
    {
        $scopes = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($sourceHost === '' || $sourceHost === '*' || $site->getBase()->getHost() === $sourceHost) {
                $scopes[] = ['site' => $site, 'language' => null];
                continue;
            }
            foreach ($site->getLanguages() as $language) {
                if ($language->getBase()->getHost() === $sourceHost) {
                    $scopes[] = ['site' => $site, 'language' => $language];
                    break;
                }
            }
        }

        return $scopes;
    }

    /**
     * The language a target is in and its path without the language prefix. A language of its own domain
     * wins; otherwise a prefix of the site base path (/de/) decides; otherwise it is the default language.
     *
     * @return array{0: int, 1: string}
     */
    private function resolveLanguage(Site $site, ?SiteLanguage $hostLanguage, string $target): array
    {
        if ($hostLanguage !== null) {
            // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
            // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
            return [$hostLanguage->getLanguageId(), $target];
        }

        $path = '/' . trim((string) parse_url($target, PHP_URL_PATH), '/');
        $sitePath = rtrim($site->getBase()->getPath(), '/');
        foreach ($site->getLanguages() as $language) {
            if ($language->getBase()->getHost() !== $site->getBase()->getHost()) {
                continue;
            }
            $prefix = '/' . trim(substr(rtrim($language->getBase()->getPath(), '/'), strlen($sitePath)), '/');
            if ($prefix !== '/' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
                // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
                return [$language->getLanguageId(), substr($path, strlen($prefix)) ?: '/'];
            }
        }

        // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
        // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
        return [$site->getDefaultLanguage()->getLanguageId(), $target];
    }

    /**
     * All pages that are not deleted, with the parent map needed to find the site a page belongs to.
     *
     * @return array{rows: list<array<string, mixed>>, parents: array<int, int>}
     */
    private function loadPages(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'pid', 'title', 'slug', 'doktype', 'hidden', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->executeQuery()
            ->fetchAllAssociative();

        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) $row['uid']] = (int) $row['pid'];
        }

        return ['rows' => $rows, 'parents' => $parents];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function defaultLanguageUid(array $row): int
    {
        return (int) $row['l10n_parent'] > 0 ? (int) $row['l10n_parent'] : (int) $row['uid'];
    }

    /**
     * The root page of the site a page belongs to (0 if none), found by walking up the parent map.
     *
     * @param array<int, int> $parents
     * @param list<int> $siteRootIds
     */
    private function findSiteRootId(int $uid, array $parents, array $siteRootIds): int
    {
        for ($depth = 0; $depth < 100 && $uid > 0; $depth++) {
            if (in_array($uid, $siteRootIds, true)) {
                return $uid;
            }
            $uid = $parents[$uid] ?? 0;
        }

        return 0;
    }

    /**
     * Lower-case path without host, query, fragment and surrounding slashes; '' for targets without
     * a path to compare (page links, empty).
     */
    private static function normalisePath(string $target): string
    {
        if (str_starts_with($target, 't3://')) {
            return '';
        }
        $path = (string) parse_url($target, PHP_URL_PATH);

        return strtolower(trim($path, '/'));
    }

    private static function similarity(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent;
    }
}
