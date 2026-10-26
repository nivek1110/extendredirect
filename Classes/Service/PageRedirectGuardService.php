<?php

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Disables redirects targeting a page that just got deleted or hidden, and logs why.
 *
 * @see \Nivek1110\Extendredirect\Hooks\DataHandlerHook
 */
class PageRedirectGuardService
{
    public function __construct(private readonly SiteFinder $siteFinder) {}

    /**
     * Disables every redirect targeting the given page - either directly via a
     * "t3://page?uid=..." link, or via a plain path/URL that is the page's URL in its site and
     * language - and writes a disable log entry explaining why.
     */
    public function disableRedirectsTargetingPage(int $pageUid, string $reasonKey): void
    {
        $this->disableForPage($pageUid, $reasonKey);

        // One summary mail for all redirects of this page (a no-op in "single" mode or when off).
        GeneralUtility::makeInstance(DisableNotificationService::class)->flush();
    }

    /**
     * Same for a page that is hidden: its translations are not reachable any more either, and DataHandler does
     * not hide them with it (unlike deleting, where it deletes them and the delete hook fires for each).
     */
    public function disableRedirectsTargetingPageAndTranslations(int $pageUid, string $reasonKey): void
    {
        $this->disableForPage($pageUid, $reasonKey);
        foreach ($this->findTranslationUids($pageUid) as $translationUid) {
            $this->disableForPage($translationUid, $reasonKey);
        }

        GeneralUtility::makeInstance(DisableNotificationService::class)->flush();
    }

    protected function disableForPage(int $pageUid, string $reasonKey): void
    {
        $linkHandler = GeneralUtility::makeInstance(PageLinkHandler::class);
        $t3Link = $linkHandler->asString(['pageuid' => $pageUid]);
        $page = BackendUtility::getRecord('pages', $pageUid, 'uid,slug,sys_language_uid,l10n_parent');
        $slug = is_array($page) && is_string($page['slug'] ?? null) ? $page['slug'] : null;

        $redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
        $redirects = $redirectRepository->findRedirectsTargetingPage($t3Link, $slug);

        foreach ($redirects as $redirect) {
            if (is_array($page) && !$this->targetsPage($redirect, $t3Link, $page)) {
                continue;
            }
            $this->disableRedirectForTargetPage($redirect, $reasonKey, $redirectRepository);
        }
    }

    /**
     * @return list<int>
     */
    protected function findTranslationUids(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return array_map('intval', $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchFirstColumn());
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

    /**
     * Whether a redirect that matched by page link or by the end of its path really points at this page. A path
     * only counts if it is the page's URL: the slug behind the base path of the page's language, on a host of
     * that language - so the same slug on another site, in another language or deeper in the tree is not hit.
     * A page outside every configured site cannot be told apart and keeps the plain slug match.
     *
     * @param array<mixed> $redirect
     * @param array<string, mixed> $page
     */
    protected function targetsPage(array $redirect, string $t3Link, array $page): bool
    {
        $target = (string) ($redirect['target'] ?? '');
        if (str_starts_with($target, $t3Link)) {
            return true;
        }

        $isRootSlug = trim((string) ($page['slug'] ?? ''), '/') === '';

        try {
            $site = $this->siteFinder->getSiteByPageId((int) ($page['l10n_parent'] ?? 0) > 0 ? (int) $page['l10n_parent'] : (int) $page['uid']);
        } catch (\Throwable) {
            // Outside every site only the plain slug can be compared - which tells nothing for the root slug.
            return !$isRootSlug;
        }

        $path = '/' . trim((string) parse_url($target, PHP_URL_PATH), '/');
        $targetHost = parse_url($target, PHP_URL_HOST);
        $sourceHost = (string) ($redirect['source_host'] ?? '*');
        $slug = '/' . trim((string) ($page['slug'] ?? ''), '/');
        $languageId = (int) ($page['sys_language_uid'] ?? 0);

        foreach ($site->getLanguages() as $language) {
            // -1: the page exists in every language
            // @extensionScannerIgnoreLine SiteLanguage::getLanguageId(), not the removed TypoScriptFrontendController API
            if ($languageId !== -1 && $language->getLanguageId() !== $languageId) {
                continue;
            }
            $base = $language->getBase();
            $expectedPath = '/' . trim(rtrim($base->getPath(), '/') . $slug, '/');
            if (strcasecmp($path, $expectedPath) !== 0) {
                continue;
            }
            $hostMatches = is_string($targetHost)
                ? strcasecmp($targetHost, $base->getHost()) === 0
                : in_array($sourceHost, ['*', '', $base->getHost()], true);
            if ($hostMatches) {
                return true;
            }
        }

        return false;
    }
}
