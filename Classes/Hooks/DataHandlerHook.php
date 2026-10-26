<?php

namespace Nivek1110\Extendredirect\Hooks;

use Nivek1110\Extendredirect\Service\HealthCheckService;
use Nivek1110\Extendredirect\Service\PageRedirectGuardService;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook covering two, otherwise unrelated, concerns that both need to
 * react to core save/delete events. All actual logic is delegated to dedicated
 * services - this class only detects which event fired and routes it:
 * - sys_redirect saves trigger an immediate health check (HealthCheckService::runHealthCheckForSavedRecord()).
 * - Deleting or hiding a page disables any redirect targeting it (PageRedirectGuardService).
 */
class DataHandlerHook
{
    /**
     * @param array<mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table === 'sys_redirect' && $status === 'update') {
            if (array_key_exists('target', $fieldArray)) {
                $fieldArray['disabled'] = 0;
            }
        }
    }

    /**
     * @param array<mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler &$dataHandler): void
    {
        if ($table === 'sys_redirect' && $status === 'update') {
            if ((array_key_exists('disabled', $fieldArray) && $fieldArray['disabled'] === '0') || empty($fieldArray) || array_key_exists('target', $fieldArray)) {
                GeneralUtility::makeInstance(HealthCheckService::class)->runHealthCheckForSavedRecord((int) $id, $dataHandler);
            }
        }

        if ($table === 'sys_redirect' && $status === 'new') {
            // Without a substituted uid there is no record to check - and findByUid(0) returning
            // false would make the health check fall back to checking every redirect.
            $newUid = (int) ($dataHandler->substNEWwithIDs[(string) $id] ?? 0);
            if ($newUid > 0) {
                GeneralUtility::makeInstance(HealthCheckService::class)
                    ->runHealthCheckForSavedRecord($newUid, $dataHandler);
            }
        }

        if ($table === 'pages' && $status === 'update' && array_key_exists('hidden', $fieldArray) && (int) $fieldArray['hidden'] === 1) {
            GeneralUtility::makeInstance(PageRedirectGuardService::class)
                ->disableRedirectsTargetingPageTree((int) $id, 'healthCheck.targetPageHidden');
        }
    }

    /**
     * Fires before a record is actually deleted. Used to catch page deletions so
     * redirects targeting the page can be disabled while it - and its slug - can
     * still be looked up.
     *
     * @param array<mixed> $recordToDelete
     */
    public function processCmdmap_deleteAction(string $table, int $id, array $recordToDelete, bool &$recordWasDeleted, DataHandler $dataHandler): void
    {
        if ($table !== 'pages') {
            return;
        }

        GeneralUtility::makeInstance(PageRedirectGuardService::class)
            ->disableRedirectsTargetingPageTree($id, 'healthCheck.targetPageDeleted');
    }
}
