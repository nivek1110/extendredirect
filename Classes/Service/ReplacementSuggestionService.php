<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    /**
     * Suggestions for the broken redirects among the given disable log entries.
     *
     * Entries whose redirect is gone, healthy again or was disabled by hand get none. The pages are
     * loaded once for all entries.
     *
     * @param array<int, array<string, mixed>> $logEntries
     * @return array<int, list<array{uid: int, title: string, slug: string, score: int}>> by log entry uid
     */
    public function suggestForLogEntries(array $logEntries): array
    {
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class);
        $pages = null;
        $result = [];

        foreach ($logEntries as $entry) {
            $redirect = $redirects->findByUid((int) $entry['redirect_uid']);
            if (!is_array($redirect) || (int) $redirect['disabled'] !== 1 || (int) ($redirect['broken_redirect'] ?? 0) !== 1) {
                continue;
            }
            $pages ??= $this->findLinkablePages();
            $result[(int) $entry['uid']] = self::rank((string) $redirect['target'], $pages);
        }

        return $result;
    }

    /**
     * Visible pages in the default language, without folders, external links and the like.
     *
     * @return list<array{uid: int, title: string, slug: string}>
     */
    public function findLinkablePages(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $expr = $queryBuilder->expr();

        $rows = $queryBuilder
            ->select('uid', 'title', 'slug')
            ->from('pages')
            ->where(
                $expr->eq('sys_language_uid', 0),
                $expr->in('doktype', self::LINKABLE_DOKTYPES),
                $expr->neq('slug', $queryBuilder->createNamedParameter(''))
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'uid' => (int) $row['uid'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
        ], $rows);
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
