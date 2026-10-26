<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Service;

use Nivek1110\Extendredirect\Service\ReplacementSuggestionService;
use PHPUnit\Framework\TestCase;

class ReplacementSuggestionServiceTest extends TestCase
{
    /**
     * @return list<array{uid: int, title: string, slug: string}>
     */
    private function pages(): array
    {
        return [
            ['uid' => 1, 'title' => 'Home', 'slug' => '/'],
            ['uid' => 2, 'title' => 'Produkte', 'slug' => '/produkte'],
            ['uid' => 3, 'title' => 'Schuhe', 'slug' => '/produkte/schuhe'],
            ['uid' => 4, 'title' => 'Kontakt', 'slug' => '/kontakt'],
            ['uid' => 5, 'title' => 'Impressum', 'slug' => '/impressum'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $suggestions
     * @return list<int>
     */
    private function uids(array $suggestions): array
    {
        return array_map(static fn(array $s): int => $s['uid'], $suggestions);
    }

    public function testASimilarSlugIsRankedFirstWithItsScore(): void
    {
        $suggestions = ReplacementSuggestionService::rank('/produkte/schuhe-neu', $this->pages());

        self::assertSame(3, $suggestions[0]['uid']);
        self::assertSame('Schuhe', $suggestions[0]['title']);
        self::assertSame('/produkte/schuhe', $suggestions[0]['slug']);
        self::assertGreaterThanOrEqual(ReplacementSuggestionService::MIN_SCORE, $suggestions[0]['score']);
        self::assertLessThanOrEqual(100, $suggestions[0]['score']);
    }

    public function testTheNearestExistingAncestorIsOffered(): void
    {
        $suggestions = ReplacementSuggestionService::rank('/produkte/alles-ganz-anders', $this->pages());

        self::assertSame([2], $this->uids($suggestions));
    }

    public function testUnrelatedPagesAreNotSuggested(): void
    {
        self::assertSame([], ReplacementSuggestionService::rank('/xyzzy/qwertz', $this->pages()));
    }

    public function testHostAndQueryOfTheTargetAreIgnored(): void
    {
        $suggestions = ReplacementSuggestionService::rank('https://example.com/kontakt-alt/?utm=1#top', $this->pages());

        self::assertSame([4], $this->uids($suggestions));
    }

    public function testAPageLinkTargetHasNoPathToCompare(): void
    {
        self::assertSame([], ReplacementSuggestionService::rank('t3://page?uid=99', $this->pages()));
    }

    public function testTheRootPageIsNeverSuggested(): void
    {
        self::assertNotContains(1, $this->uids(ReplacementSuggestionService::rank('/home', $this->pages())));
        self::assertSame([], ReplacementSuggestionService::rank('/', $this->pages()));
    }

    public function testAtMostThreeSuggestionsBestFirst(): void
    {
        $pages = [];
        foreach (['/news', '/news-1', '/news-2', '/news-3', '/news-4'] as $i => $slug) {
            $pages[] = ['uid' => $i + 10, 'title' => $slug, 'slug' => $slug];
        }

        $suggestions = ReplacementSuggestionService::rank('/news-5', $pages);

        self::assertCount(3, $suggestions);
        $scores = array_map(static fn(array $s): int => $s['score'], $suggestions);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
    }

    public function testMatchingIgnoresCase(): void
    {
        self::assertSame(3, ReplacementSuggestionService::rank('/Produkte/SCHUHE-neu', $this->pages())[0]['uid']);
    }
}
