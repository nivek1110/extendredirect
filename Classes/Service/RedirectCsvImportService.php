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
    private const OPTIONAL_BOOLEAN_FIELDS = ['respect_query_parameters', 'keep_query_parameters', 'force_https'];
    private const REQUIRED_FIELDS = ['source_host', 'source_path', 'target', 'target_statuscode'];

    /**
     * @return array{counter: int, imported: int, ignoreCounter: int, messages: array<int, array{message: string, title: string, severity: ContextualFeedbackSeverity}>}
     */
    public function importFromCsvFile(string $uploadedFile): array
    {
        $counter = 0;
        $imported = 0;
        $ignoreCounter = 0;
        $messages = [];

        $file = fopen($uploadedFile, 'r');
        $flag = true;
        $header = [];

        if (is_resource($file)) {
            while (($data = fgetcsv($file, 9999, ';')) !== false) {
                if ($flag) {
                    $header = array_map(static fn($value): string => (string) $value, $data);
                    $flag = false;
                    continue;
                }

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
                    continue;
                }

                if (GeneralUtility::makeInstance(RedirectRepository::class)->existsBySourcePath((string) $data['source_path'], (string) $data['source_host'])) {
                    $ignoreCounter++;
                    continue;
                }

                if (!in_array((int) $data['target_statuscode'], self::ALLOWED_STATUS_CODES, true)) {
                    $data['target_statuscode'] = 308;
                } else {
                    $data['target_statuscode'] = (int) $data['target_statuscode'];
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

                $healthCheckService = GeneralUtility::makeInstance(HealthCheckService::class);
                $page = $healthCheckService->getPageIdFromPath((string) $data['target']);
                if (is_array($page) && array_key_exists('uid', $page)) {
                    $linkHandler = GeneralUtility::makeInstance(PageLinkHandler::class);
                    $url = $linkHandler->asString(['pageuid' => $page['uid']]);
                    if ($url) {
                        $data['target'] = $url;
                    }
                }

                $redirect['sys_redirect']['NEW9823be87'] = $data;
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start($redirect, []);
                $dataHandler->process_datamap();
                $imported++;
            }
        }

        return [
            'counter' => $counter,
            'imported' => $imported,
            'ignoreCounter' => $ignoreCounter,
            'messages' => $messages,
        ];
    }

    /**
     * @return string[]
     */
    protected function getKnownSiteHosts(): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        return array_map(
            static fn($site): string => $site->getBase()->getHost(),
            array_values($siteFinder->getAllSites())
        );
    }
}
