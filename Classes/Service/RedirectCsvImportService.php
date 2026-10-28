<?php

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Imports redirects from a semicolon-separated CSV file.
 *
 * @see \Nivek1110\Extendredirect\Controller\ManagementController::importRedirect()
 */
class RedirectCsvImportService
{
    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];
    private const OPTIONAL_BOOLEAN_FIELDS = ['respect_query_parameters', 'keep_query_parameters', 'force_https', 'is_regexp', 'disabled'];
    private const REQUIRED_FIELDS = ['source_host', 'source_path', 'target', 'target_statuscode'];

    /** Rows of a preview that are listed in detail; the counts are always complete. */
    private const PREVIEW_ROW_LIMIT = 200;

    /**
     * @param bool $overwrite a row whose source host and path match an existing redirect exactly updates it;
     *                        without it such a row is skipped. Looser matches (a wildcard host row against a
     *                        host row) are skipped either way.
     * @param bool $dryRun    decide everything and count it, but save nothing; "rows" lists what would happen
     *
     * @return array{counter: int, imported: int, updated: int, unchanged: int, ignoreCounter: int, dryRun: bool, rowsTruncated: bool, rows: list<array{line: int, action: string, source: string, target: string, changes: array<string, array{0: mixed, 1: mixed}>, reason: string}>, messages: array<int, array{message: string, title: string, severity: ContextualFeedbackSeverity}>}
     */
    public function importFromCsvFile(string $uploadedFile, bool $overwrite = false, bool $dryRun = false): array
    {
        $counter = 0;
        $imported = 0;
        $updated = 0;
        $unchanged = 0;
        $ignoreCounter = 0;
        $messages = [];
        $rows = [];
        $rowsTruncated = false;
        $seen = [];
        $line = 1;
        $repository = GeneralUtility::makeInstance(RedirectRepository::class);

        $record = static function (string $action, array $data, array $changes = [], string $reason = '') use (&$rows, &$rowsTruncated, $dryRun, &$line): void {
            if (!$dryRun) {
                return;
            }
            if (count($rows) >= self::PREVIEW_ROW_LIMIT) {
                $rowsTruncated = true;

                return;
            }
            $rows[] = [
                'line' => $line,
                'action' => $action,
                'source' => (string) ($data['source_host'] ?? '') . (string) ($data['source_path'] ?? ''),
                'target' => (string) ($data['target'] ?? ''),
                'changes' => $changes,
                'reason' => $reason,
            ];
        };

        $file = fopen($uploadedFile, 'r');
        $flag = true;
        $header = [];

        if (is_resource($file)) {
            while (($data = fgetcsv($file, 9999, ';', '"', '')) !== false) {
                if ($flag) {
                    $header = array_map(static fn($value): string => (string) $value, $data);
                    $flag = false;
                    continue;
                }
                $line++;

                $headerCount = count($header);
                $dataCount = count($data);
                if ($headerCount > $dataCount) {
                    for ($i = 0; $i < $headerCount - $dataCount; $i++) {
                        $data[] = '';
                    }
                }
                $data = array_combine($header, $data);
                $counter++;

                $missingFields = array_filter(
                    self::REQUIRED_FIELDS,
                    static fn(string $field): bool => !isset($data[$field])
                );
                if ($missingFields !== []) {
                    $messages[] = [
                        'message' => sprintf(
                            $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.importInvalidCsv.message'),
                            implode(', ', $missingFields)
                        ),
                        'title' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.importInvalidCsv.title'),
                        'severity' => ContextualFeedbackSeverity::ERROR,
                    ];
                    break;
                }

                if ($data['source_host'] !== '*' && !in_array($data['source_host'], $this->getKnownSiteHosts(), true)) {
                    $messages[] = [
                        'message' => sprintf(
                            $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.importUnknownSourceHost.message'),
                            $data['source_host'],
                            $data['source_path']
                        ),
                        'title' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.importUnknownSourceHost.title'),
                        'severity' => ContextualFeedbackSeverity::ERROR,
                    ];
                    $ignoreCounter++;
                    $record('skipped', $data, [], 'unknownHost');
                    continue;
                }

                // The same source twice in the file: the first row wins, in a preview as in a real run.
                $key = $data['source_host'] . "\0" . $data['source_path'];
                if (isset($seen[$key])) {
                    $ignoreCounter++;
                    $record('skipped', $data, [], 'duplicateInFile');
                    continue;
                }
                $seen[$key] = true;

                $existing = $overwrite ? $repository->findBySource((string) $data['source_host'], (string) $data['source_path']) : null;
                if ($existing === null && $repository->existsBySourcePath((string) $data['source_path'], (string) $data['source_host'])) {
                    $ignoreCounter++;
                    $record('skipped', $data, [], 'exists');
                    continue;
                }

                if (!in_array((int) $data['target_statuscode'], self::ALLOWED_STATUS_CODES, true)) {
                    $data['target_statuscode'] = 308;
                } else {
                    $data['target_statuscode'] = (int) $data['target_statuscode'];
                }

                $healthCheckService = GeneralUtility::makeInstance(HealthCheckService::class);
                $page = $healthCheckService->getPageIdFromPath((string) $data['target']);
                if (is_array($page) && array_key_exists('uid', $page)) {
                    $linkHandler = GeneralUtility::makeInstance(PageLinkHandler::class);
                    $url = $linkHandler->asString(['pageuid' => $page['uid']]);
                    if ($url) {
                        $data['target'] = $url;
                    }
                }

                if ($existing !== null) {
                    // An empty cell of a column that is in the file means "off"; columns that are not in the file
                    // stay as they are.
                    $values = ['target' => (string) $data['target'], 'target_statuscode' => $data['target_statuscode']];
                    foreach (self::OPTIONAL_BOOLEAN_FIELDS as $field) {
                        if (array_key_exists($field, $data)) {
                            $values[$field] = $data[$field] !== '' ? 1 : 0;
                        }
                    }
                    $changes = [];
                    foreach ($values as $field => $value) {
                        if (is_int($value) ? (int) $existing[$field] !== $value : (string) $existing[$field] !== $value) {
                            $changes[$field] = [$existing[$field], $value];
                        }
                    }
                    if ($changes === []) {
                        $unchanged++;
                        $record('unchanged', $data);
                        continue;
                    }
                    if (!$dryRun) {
                        // Like an editor's save: a changed target is enabled and checked right away by the hook.
                        $update['sys_redirect'][(int) $existing['uid']] = array_map(static fn(array $change): mixed => $change[1], $changes);
                        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                        $dataHandler->start($update, []);
                        $dataHandler->process_datamap();
                        unset($update);
                    }
                    $updated++;
                    $record('updated', $data, $changes);
                    continue;
                }

                foreach (self::OPTIONAL_BOOLEAN_FIELDS as $field) {
                    if (isset($data[$field]) && $data[$field] !== '') {
                        $data[$field] = 1;
                    } else {
                        unset($data[$field]);
                    }
                }

                // Always "automatically created" for imported redirects - not something the CSV controls.
                $data['creation_type'] = 0;
                $data['pid'] = '0';

                if (!$dryRun) {
                    $redirect['sys_redirect']['NEW9823be87'] = $data;
                    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                    $dataHandler->start($redirect, []);
                    $dataHandler->process_datamap();
                }
                $imported++;
                $record('imported', $data);
            }
        }

        return [
            'counter' => $counter,
            'imported' => $imported,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'ignoreCounter' => $ignoreCounter,
            'dryRun' => $dryRun,
            'rowsTruncated' => $rowsTruncated,
            'rows' => $rows,
            'messages' => $messages,
        ];
    }

    /**
     * @return string[]
     */
    protected function getKnownSiteHosts(): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        // The base of every site and the domains of languages that have one of their own.
        $hosts = [];
        foreach ($siteFinder->getAllSites() as $site) {
            $hosts[] = $site->getBase()->getHost();
            foreach ($site->getLanguages() as $language) {
                $hosts[] = $language->getBase()->getHost();
            }
        }

        return array_values(array_unique(array_filter($hosts, static fn(string $host): bool => $host !== '')));
    }
}
