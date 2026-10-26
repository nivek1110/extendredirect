<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Service;

use Nivek1110\Extendredirect\Service\RedirectCsvExportService;
use PHPUnit\Framework\TestCase;

class RedirectCsvExportServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function redirect(array $overrides = []): array
    {
        return array_merge([
            'source_host' => 'example.com',
            'source_path' => '/old',
            'target' => '/new',
            'target_statuscode' => 301,
            'respect_query_parameters' => 0,
            'keep_query_parameters' => 0,
            'force_https' => 0,
            'is_regexp' => 0,
            'disabled' => 0,
        ], $overrides);
    }

    /**
     * @return list<list<string|null>>
     */
    private function parse(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, $csv);
        rewind($handle);
        while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testHeaderMatchesTheImportFormatAndAddsRegexAndDisabledColumns(): void
    {
        $rows = $this->parse((new RedirectCsvExportService())->toCsv([]));

        self::assertSame([[
            'source_host', 'source_path', 'target', 'target_statuscode',
            'respect_query_parameters', 'keep_query_parameters', 'force_https', 'is_regexp', 'disabled',
        ]], $rows);
    }

    public function testRowsUseSemicolonsAndEmptyCellsForDisabledFlags(): void
    {
        $csv = (new RedirectCsvExportService())->toCsv([$this->redirect(['force_https' => 1, 'disabled' => 1])]);

        self::assertStringContainsString("\nexample.com;/old;/new;301;;;1;;1\n", $csv);
    }

    public function testValuesWithSeparatorsAndQuotesRoundTripThroughACsvParser(): void
    {
        $tricky = $this->redirect([
            'source_path' => '/a;b"c',
            'target' => "https://example.org/x?y=1;z=\"2\"\\\"end",
        ]);

        $rows = $this->parse((new RedirectCsvExportService())->toCsv([$tricky]));

        self::assertSame('/a;b"c', $rows[1][1]);
        self::assertSame("https://example.org/x?y=1;z=\"2\"\\\"end", $rows[1][2]);
    }

    public function testCellsThatSpreadsheetsWouldExecuteAreNeutralised(): void
    {
        $rows = $this->parse((new RedirectCsvExportService())->toCsv([
            $this->redirect(['source_path' => '=HYPERLINK("http://evil","x")', 'target' => '+1']),
            $this->redirect(['source_path' => '@SUM(1)', 'target' => '-2']),
            $this->redirect(['source_path' => "\tcmd", 'target' => '/ok']),
        ]));

        self::assertSame("'=HYPERLINK(\"http://evil\",\"x\")", $rows[1][1]);
        self::assertSame("'+1", $rows[1][2]);
        self::assertSame("'@SUM(1)", $rows[2][1]);
        self::assertSame("'-2", $rows[2][2]);
        self::assertSame("'\tcmd", $rows[3][1]);
        self::assertSame('/ok', $rows[3][2]);
    }

    public function testRegularPathsAndRegexesAreLeftUntouched(): void
    {
        $rows = $this->parse((new RedirectCsvExportService())->toCsv([
            $this->redirect(['source_path' => '#^/old/(\d+)$#', 'target' => 't3://page?uid=5', 'is_regexp' => 1]),
        ]));

        self::assertSame('#^/old/(\d+)$#', $rows[1][1]);
        self::assertSame('t3://page?uid=5', $rows[1][2]);
        self::assertSame('1', $rows[1][7]);
    }
}
