<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Exports redirects in the format RedirectCsvImportService reads: semicolon separated, one header
 * row, the four required columns plus the optional flags. "is_regexp" and "disabled" are exported
 * too, so regular expressions and parked redirects survive an export and a re-import.
 *
 * Cells a spreadsheet would execute as a formula (starting with = + - @, a tab or a carriage return)
 * get a leading apostrophe, because an export is typically opened in Excel or LibreOffice.
 *
 * @see RedirectCsvImportService
 */
class RedirectCsvExportService
{
    public const TEXT_COLUMNS = ['source_host', 'source_path', 'target', 'target_statuscode'];
    public const FLAG_COLUMNS = ['respect_query_parameters', 'keep_query_parameters', 'force_https', 'is_regexp', 'disabled'];

    /**
     * Every redirect of the installation, sorted by host and path.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function export(): string
    {
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        usort(
            $redirects,
            static fn(array $a, array $b): int => [(string) $a['source_host'], (string) $a['source_path'], (int) $a['uid']]
                <=> [(string) $b['source_host'], (string) $b['source_path'], (int) $b['uid']]
        );

        return $this->toCsv($redirects);
    }

    /**
     * @param array<int, array<string, mixed>> $redirects sys_redirect rows
     */
    public function toCsv(array $redirects): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV export.', 1790550001);
        }

        fputcsv($handle, [...self::TEXT_COLUMNS, ...self::FLAG_COLUMNS], ';', '"', '');
        foreach ($redirects as $redirect) {
            $row = [];
            foreach (self::TEXT_COLUMNS as $column) {
                $row[] = $this->neutralise((string) ($redirect[$column] ?? ''));
            }
            foreach (self::FLAG_COLUMNS as $column) {
                // The import enables a flag by any non-empty cell and skips an empty one.
                $row[] = (int) ($redirect[$column] ?? 0) !== 0 ? '1' : '';
            }
            fputcsv($handle, $row, ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function neutralise(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}
