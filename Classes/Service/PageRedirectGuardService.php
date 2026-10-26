<?php

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Disables redirects targeting a page that just got deleted or hidden, and logs why.
 *
 * @see \Nivek1110\Extendredirect\Hooks\DataHandlerHook
 */
class PageRedirectGuardService
{
    /**
     * Disables every redirect targeting the given page - either directly via a
     * "t3://page?uid=..." link, or via a plain path/URL matching the page's slug -
     * and writes a disable log entry explaining why.
     */
    public function disableRedirectsTargetingPage(int $pageUid, string $reasonKey): void
    {
        $linkHandler = GeneralUtility::makeInstance(PageLinkHandler::class);
        $t3Link = $linkHandler->asString(['pageuid' => $pageUid]);
        $slug = $this->findPageSlug($pageUid);

        $redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
        $redirects = $redirectRepository->findRedirectsTargetingPage($t3Link, $slug);

        foreach ($redirects as $redirect) {
            $this->disableRedirectForTargetPage($redirect, $reasonKey, $redirectRepository);
        }

        // One summary mail for all redirects of this page (a no-op in "single" mode or when off).
        GeneralUtility::makeInstance(DisableNotificationService::class)->flush();
    }

    /**
     * @param array<mixed> $redirect
     */
    protected function disableRedirectForTargetPage(array $redirect, string $reasonKey, RedirectRepository $redirectRepository): void
    {
        if ((int) ($redirect['disabled'] ?? 0) === 1) {
            return;
        }

        $redirectRepository->disable((int) $redirect['uid']);

        $entry = [
            'pid' => 0,
            'crdate' => GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'),
            'redirect_uid' => (int) $redirect['uid'],
            'source_host' => (string) ($redirect['source_host'] ?? ''),
            'source_path' => (string) ($redirect['source_path'] ?? ''),
            'target' => (string) ($redirect['target'] ?? ''),
            'status_code' => '',
            'reason' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:' . $reasonKey),
        ];
        GeneralUtility::makeInstance(DisableLogRepository::class)->add($entry);
        GeneralUtility::makeInstance(DisableNotificationService::class)->queue($entry);
    }

    protected function findPageSlug(int $pageUid): ?string
    {
        $page = BackendUtility::getRecord('pages', $pageUid, 'slug');

        return is_string($page['slug'] ?? null) ? $page['slug'] : null;
    }
}
