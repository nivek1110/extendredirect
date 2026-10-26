<?php

namespace Nivek1110\Extendredirect\Service;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RequestHandler;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Redirects\Hooks\DataHandlerCacheFlushingHook;
use TYPO3\CMS\Redirects\Service\RedirectCacheService;

class HealthCheckService
{
    /**
     * Sentinel returned by resolveTargetPage() for a target whose host doesn't
     * belong to any configured site - nothing local to verify it against.
     */
    private const TARGET_EXTERNAL = 'external';

    private const MAX_CHAIN_HOPS = 10;

    /**
     * Context aspects the frontend sub-request may overwrite and that the surrounding
     * (backend) request must get back unchanged - see redirectFollower().
     */
    private const RESTORED_CONTEXT_ASPECTS = ['backend.user', 'frontend.user', 'workspace', 'visibility'];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SiteFinder $siteFinder
    ) {}

    /**
     * Health check
     *
     * @param bool|array<mixed> $redirect
     *
     * @return null|array<mixed>
     * @throws Exception
     */
    public function runHealthCheck(bool|array $redirect = false): ?array
    {
        if (is_array($redirect)) {
            $check = $this->checkRedirect($redirect);
            if ($redirect['disabled'] === 0) {
                $this->updateRedirect($redirect, $check);
            }
            GeneralUtility::makeInstance(DisableNotificationService::class)->flush();

            return $check;
        }

        $this->runBulkCheck();

        return null;
    }

    /**
     * Checks every redirect, honouring the knobs the CLI/Scheduler command exposes.
     *
     * A redirect an editor disabled by hand (not flagged broken by an earlier
     * check) is skipped and stays disabled - only our own, healed redirects are
     * re-enabled.
     *
     * @param bool $dryRun     only report what would be disabled, change nothing
     * @param int  $delayMs    pause after each checked redirect, to throttle the
     *                         sub-requests the "request" check mode fires
     * @param int  $maxRuntime stop starting new checks after this many seconds (0 = no limit)
     * @param int  $limit      check at most this many redirects, least recently checked first (0 = no limit)
     *
     * @return array{checked: int, skipped: int, remaining: int, broken: list<array{uid: int, source: string, message: string, newlyDisabled: bool}>}
     * @throws Exception
     */
    public function runBulkCheck(bool $dryRun = false, int $delayMs = 0, int $maxRuntime = 0, int $limit = 0): array
    {
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        $result = ['checked' => 0, 'skipped' => 0, 'remaining' => 0, 'broken' => []];
        $startedAt = microtime(true);

        $isSkipped = static fn(array $redirect): bool => (int) $redirect['disabled'] === 1
            && (int) ($redirect['broken_redirect'] ?? 0) === 0;

        foreach ($redirects as $index => $redirect) {
            if ($isSkipped($redirect)) {
                $result['skipped']++;
                continue;
            }

            if (($maxRuntime > 0 && microtime(true) - $startedAt >= $maxRuntime)
                || ($limit > 0 && $result['checked'] >= $limit)) {
                $result['remaining'] = count(array_filter(array_slice($redirects, $index), static fn(array $r): bool => !$isSkipped($r)));
                break;
            }

            // checkRedirect() may rewrite the target, but never touches "disabled".
            $wasEnabled = (int) $redirect['disabled'] === 0;
            $check = $this->checkRedirect($redirect);
            $result['checked']++;

            if ($check['redirectError']) {
                $result['broken'][] = [
                    'uid' => (int) $redirect['uid'],
                    'source' => (string) $redirect['source_host'] . (string) $redirect['source_path'],
                    'message' => (string) $check['message'],
                    // false: still broken, but it was already disabled before this run
                    // (in a dry run: true = it would be disabled now)
                    'newlyDisabled' => $wasEnabled,
                ];
            }

            if (!$dryRun) {
                $this->updateRedirect($redirect, $check);
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        // One summary mail for everything this run disabled (a no-op in "single" mode or when off).
        GeneralUtility::makeInstance(DisableNotificationService::class)->flush();

        return $result;
    }

    /**
     * Reacts to a sys_redirect record having just been saved via DataHandler:
     * runs the health check on it immediately and reports the outcome as a
     * backend flash message.
     *
     * @see \Nivek1110\Extendredirect\Hooks\DataHandlerHook
     */
    public function runHealthCheckForSavedRecord(int $id, DataHandler $dataHandler): void
    {
        $redirect = GeneralUtility::makeInstance(RedirectRepository::class)->findByUid($id);

        $dataHandlerCacheHook = GeneralUtility::makeInstance(DataHandlerCacheFlushingHook::class);
        $dataHandlerCacheHook->rebuildRedirectCacheIfNecessary(['table' => 'sys_redirect', 'uid' => $id], $dataHandler);

        $message = $this->runHealthCheck($redirect);

        if (is_array($message) && array_key_exists('redirectError', $message) && $message['redirectError']) {
            $this->generateFlashMessage($message['message']);
        }

        if (is_array($message) && !$message['redirectError'] && $message['message']) {
            $this->generateFlashMessage(
                $message['message'],
                $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.adjusted.title'),
                ContextualFeedbackSeverity::INFO
            );
        }
    }

    protected function generateFlashMessage(?string $message = null, ?string $messageHeader = null, ContextualFeedbackSeverity $box = ContextualFeedbackSeverity::ERROR): void
    {
        $message ??= $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.loopDetected.message');
        $messageHeader ??= $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.loopDetected.title');
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $messageHeader,
            $box,
            true
        );
        // The classic default queue is rendered inline via <f:flashMessages> in the SAME
        // response as the save that triggered this hook. FormEngine's "Save" button submits
        // that save via AJAX and only swaps the form-body fragment back into the page,
        // discarding the rest of the response - so a message on that queue is rendered (and
        // thus drained from session) without ever reaching the visible DOM. The notification
        // queue is dispatched as a JS toast instruction on every ModuleTemplate render instead
        // (see EditDocumentController's own "record duplicated"/"record locked" messages,
        // which use this same queue for the same reason), so it survives to the next render.
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $notificationQueue = $flashMessageService->getMessageQueueByIdentifier(FlashMessageQueue::NOTIFICATION_QUEUE);
        $notificationQueue->enqueue($flashMessage);
    }

    /**
     * Update Redirect
     *
     * @param array<mixed> $redirect
     * @param array<mixed> $check
     *
     * @return void
     */
    protected function updateRedirect(array $redirect, array $check): void
    {
        // Log the reason whenever the health check deactivates a redirect that
        // was still enabled before, so the disabling can be traced afterwards.
        if ($check['redirectError'] && (int) ($redirect['disabled'] ?? 0) === 0) {
            $this->logDisable($redirect, $check);
        }

        $wasDisabled = (int) ($redirect['disabled'] ?? 0) === 1;
        $isDisabled = (bool) $check['redirectError'];

        GeneralUtility::makeInstance(RedirectRepository::class)->updateHealthCheckResult(
            (int) $redirect['uid'],
            $isDisabled,
            $check['redirectCount'],
            (string) $redirect['target']
        );

        if ($wasDisabled !== $isDisabled) {
            // The state is written with plain SQL, so the core's redirect index (cached for the frontend) must be
            // rebuilt by hand: otherwise a healed redirect never fires - and the next check, which requests the
            // source URL, wrongly finds it broken again - and a disabled one keeps redirecting.
            GeneralUtility::makeInstance(RedirectCacheService::class)->rebuildForHost((string) ($redirect['source_host'] ?? '*'));
        }

        $persistanceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persistanceManager->persistAll();
    }

    /**
     * Writes a log entry explaining why a redirect was deactivated.
     *
     * @param array<mixed> $redirect
     * @param array<mixed> $check
     */
    protected function logDisable(array $redirect, array $check): void
    {
        $entry = [
            'pid' => 0,
            'crdate' => GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'),
            'redirect_uid' => (int) $redirect['uid'],
            'source_host' => (string) ($redirect['source_host'] ?? ''),
            'source_path' => (string) ($redirect['source_path'] ?? ''),
            'target' => (string) ($redirect['target'] ?? ''),
            'status_code' => (string) ($check['redirectCount'] ?? ''),
            'reason' => (string) ($check['message'] ?? ''),
        ];
        GeneralUtility::makeInstance(DisableLogRepository::class)->add($entry);
        GeneralUtility::makeInstance(DisableNotificationService::class)->queue($entry);
    }

    protected function redirectFollower(string $location): ResponseInterface
    {
        // Dispatching a sub-request through the full application kernel re-runs the frontend
        // middleware stack, which clobbers state the surrounding BACKEND request still relies on:
        // - $GLOBALS['BE_USER'] becomes null (the fabricated request carries no backend cookie),
        //   which silently breaks session-based flash messages afterwards;
        // - $GLOBALS['TYPO3_REQUEST'] is replaced by the sub-request;
        // - the "backend.user" aspect of the shared Context is reset to "not logged in". The
        //   backend middleware wrapping the editor's save looks at exactly that aspect when it
        //   builds the response and, finding no user, deletes the session cookie - so without
        //   restoring it the editor is logged out right after saving a redirect.
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $originalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $context = GeneralUtility::makeInstance(Context::class);
        $aspects = [];
        foreach (self::RESTORED_CONTEXT_ASPECTS as $name) {
            if ($context->hasAspect($name)) {
                $aspects[$name] = $context->getAspect($name);
            }
        }

        try {
            return $this->dispatchSubRequest($location);
        } catch (\Throwable) {
            // A site that cannot render (e.g. no TypoScript: "No page configured for type=0") throws instead of
            // answering. That is a server error of this one redirect, not a reason to abort the whole run.
            return new Response('php://temp', 500);
        } finally {
            $GLOBALS['BE_USER'] = $backendUser;
            if ($originalRequest !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $originalRequest;
            }
            foreach ($aspects as $name => $aspect) {
                $context->setAspect($name, $aspect);
            }
        }
    }

    /**
     * Runs the actual frontend sub-request. Kept apart from redirectFollower() so the state
     * handling around it can be tested without booting a frontend.
     */
    protected function dispatchSubRequest(string $location): ResponseInterface
    {
        $location = $this->resolveAbsoluteLocation($location);

        $request = GeneralUtility::makeInstance(ServerRequest::class);
        $subRequest = $request->withQueryParams([])->withUri(new Uri($location))->withMethod('GET');

        return GeneralUtility::makeInstance(RequestHandler::class)->handle($subRequest);
    }

    /**
     * Resolve a protocol-relative Location header ("//host/path") into an absolute
     * URL. The redirects module emits these when a redirect's target is a plain
     * path, and TYPO3\CMS\Core\Http\Uri parses such a location with an empty
     * scheme - dispatching a sub-request with an empty scheme causes the frontend
     * to 404, even though the same URL works fine over real HTTP where the
     * browser fills in the current scheme.
     *
     * @param string $location
     *
     * @return string
     */
    protected function resolveAbsoluteLocation(string $location): string
    {
        if (!str_starts_with($location, '//')) {
            return $location;
        }

        $host = parse_url($location, PHP_URL_HOST);
        $scheme = 'https';

        foreach ($this->siteFinder->getAllSites() as $site) {
            $hosts = $this->getSiteHosts($site);
            if (is_string($host) && isset($hosts[$host])) {
                $scheme = $hosts[$host];
                break;
            }
        }

        return $scheme . ':' . $location;
    }

    /**
     * Check Redirect
     *
     * @param array<mixed> $redirect
     *
     * @return array<mixed>
     * @throws Exception
     */
    protected function checkRedirect(array &$redirect): array
    {
        $existRedirect = GeneralUtility::makeInstance(RedirectRepository::class)
            ->findDuplicatesBySourcePath((string) $redirect['source_path'], (string) $redirect['source_host']);
        if (count($existRedirect) > 1) {
            return ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.duplicateSourcePath')];
        }

        // "database" mode never fires a request, for regex and flat redirects alike -
        // see checkRedirectViaDatabase().
        if ($this->getCheckMode() === 'database') {
            return $this->checkRedirectViaDatabase($redirect);
        }

        // A regex source_path is a pattern, not a literal path - it can't be requested
        // over HTTP like a normal source. Instead of following the (non-existent) URL,
        // just verify the target still exists.
        if ((bool) ($redirect['is_regexp'] ?? false)) {
            return $this->checkRegexRedirectTarget($redirect);
        }

        // The core ignores a disabled redirect, so its source URL 404s no matter how healthy the target is -
        // the only way such a redirect can ever heal is to look at where it points.
        if ((int) ($redirect['disabled'] ?? 0) === 1) {
            return $this->checkDisabledRedirectViaTarget($redirect);
        }

        return $this->checkSourceUrls($redirect, (string) ($redirect['source_path'] ?? ''));
    }

    /**
     * "request" mode for a disabled redirect: requests the target instead of the source. A page link
     * must still resolve to a visible page, a target on another host is left alone (as in "database" mode).
     *
     * @param array<mixed> $redirect
     * @return array<mixed>
     */
    protected function checkDisabledRedirectViaTarget(array &$redirect): array
    {
        $target = (string) ($redirect['target'] ?? '');

        if (str_starts_with($target, 't3://')) {
            try {
                $link = GeneralUtility::makeInstance(LinkService::class)->resolve($target);
            } catch (\Throwable) {
                return ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
            }
            if (($link['type'] ?? '') !== LinkService::TYPE_PAGE) {
                return ['redirectError' => false, 'redirectCount' => 0, 'message' => ''];
            }
            $page = $this->findPageByUid((int) ($link['pageuid'] ?? 0));
            // "&_language=1" (or the legacy "&L=1") on the link picks a translation of the page.
            parse_str((string) parse_url($target, PHP_URL_QUERY), $linkParameters);
            $linkLanguage = $linkParameters['_language'] ?? $linkParameters['L'] ?? null;
            $url = $page === null ? null : $this->buildPageUrl($page, is_numeric($linkLanguage) ? (int) $linkLanguage : null);
            if ($url === null) {
                return ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
            }

            // The page link is already canonical: check on a copy so the check cannot rewrite it (e.g. to the
            // default language page when the URL of a translation is resolved again).
            $probe = $redirect;

            return $this->checkSingleUrl($url, $probe);
        }

        if (preg_match('#^(https?:)?//#i', $target) === 1) {
            $host = (string) parse_url($target, PHP_URL_HOST);
            if ($this->findSiteByHost($host) === null) {
                return ['redirectError' => false, 'redirectCount' => 0, 'message' => ''];
            }

            // An absolute URL of a known site is requested there, not on every site of a wildcard host.
            return $this->checkSingleUrl(str_starts_with($target, '//') ? $this->resolveAbsoluteLocation($target) : $target, $redirect);
        }

        $path = (string) parse_url($target, PHP_URL_PATH);

        return $this->checkSourceUrls($redirect, '/' . ltrim($path, '/'));
    }

    /**
     * The absolute frontend URL of a page row (a translation row gives the URL in its language), built by
     * the site's router so domain, language base and slug are right. Null if the page belongs to no site or
     * its language is not configured there.
     *
     * A language given for a default language page (from "&_language=" on a page link) means its translation,
     * which must exist.
     *
     * @param array<string, mixed> $page
     */
    protected function buildPageUrl(array $page, ?int $languageId = null): ?string
    {
        try {
            $defaultLanguagePageId = (int) ($page['l10n_parent'] ?? 0) > 0 ? (int) $page['l10n_parent'] : (int) $page['uid'];
            if ($languageId !== null && $languageId > 0 && (int) ($page['sys_language_uid'] ?? 0) === 0 && !$this->translationExists($defaultLanguagePageId, $languageId)) {
                return null;
            }
            $site = $this->siteFinder->getSiteByPageId($defaultLanguagePageId);
            $language = $site->getLanguageById($languageId !== null && (int) ($page['sys_language_uid'] ?? 0) === 0 ? $languageId : (int) ($page['sys_language_uid'] ?? 0));

            return (string) $site->getRouter()->generateUri($defaultLanguagePageId, ['_language' => $language]);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function translationExists(int $pageUid, int $languageId): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        return (bool) $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Requests $path on the site(s) the redirect's source host stands for and evaluates the answer.
     *
     * @param array<mixed> $redirect
     * @return array<mixed>
     */
    protected function checkSourceUrls(array &$redirect, string $path): array
    {
        $urls = $this->resolveUrls(['source_host' => $redirect['source_host'], 'source_path' => $path]);

        if ($urls === []) {
            $statusCode = 500;
            return ['redirectError' => true, 'redirectCount' => '-', 'message' => $statusCode . ' - ' . $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
        }

        // A specific source_host resolves to exactly one URL and its result is used
        // as-is. A wildcard ('*') source_host resolves to every configured domain -
        // per the redirects module, it matches any domain, so it only counts as
        // healthy when it resolves on all of them.
        $results = [];
        foreach ($urls as $host => $url) {
            $results[$host] = $this->checkSingleUrl($url, $redirect);
        }

        if (count($results) === 1) {
            return current($results);
        }

        foreach ($results as $host => $result) {
            if ($result['redirectError'] === true) {
                return [
                    'redirectError' => true,
                    'redirectCount' => $result['redirectCount'],
                    'message' => sprintf(
                        $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.domainCheckFailed'),
                        $host,
                        $result['message']
                    ),
                ];
            }
        }

        return current($results);
    }

    /**
     * Checks whether a regex redirect's target still exists, without touching the
     * (non-literal) source_path. A "t3://page?uid=X" target must reference an
     * existing, non-deleted page. A plain path/URL target is checked against
     * page slugs - and, same as a plain redirect's checkSingleUrl(), rewritten
     * into that canonical "t3://page?uid=..." form once resolved - unless it
     * contains capture-group placeholders ($1, $2, ...), which can't be resolved
     * to a single concrete target and are left alone.
     *
     * @param array<mixed> &$redirect
     *
     * @return array<mixed>
     */
    protected function checkRegexRedirectTarget(array &$redirect): array
    {
        $target = (string) ($redirect['target'] ?? '');

        try {
            $linkDetails = GeneralUtility::makeInstance(LinkService::class)->resolve($target);
        } catch (\Exception) {
            $linkDetails = [];
        }

        if (($linkDetails['type'] ?? null) === LinkService::TYPE_PAGE) {
            $pageUid = $linkDetails['pageuid'] ?? null;
            if (!is_numeric($pageUid) || !$this->pageExists((int) $pageUid)) {
                return ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
            }

            return ['redirectError' => false, 'redirectCount' => 0, 'message' => ''];
        }

        if (str_contains($target, '$')) {
            // Dynamic capture-group target - nothing concrete to check against.
            return ['redirectError' => false, 'redirectCount' => 0, 'message' => ''];
        }

        $targetPath = (string) parse_url($target, PHP_URL_PATH);
        $page = $this->getPageIdFromPath($targetPath);
        if (!is_array($page)) {
            return ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
        }

        $message = '';
        $correctedTarget = $this->toPageTypolink((int) $page['uid']);
        if ($correctedTarget && $correctedTarget !== $target) {
            $redirect['target'] = $correctedTarget;
            $message = $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.chainCorrected');
        }

        return ['redirectError' => false, 'redirectCount' => 0, 'message' => $message];
    }

    /**
     * Builds the canonical "t3://page?uid=..." link for a page.
     */
    protected function toPageTypolink(int $pageUid): string
    {
        return (string) GeneralUtility::makeInstance(PageLinkHandler::class)->asString(['pageuid' => $pageUid]);
    }

    protected function pageExists(int $pageUid): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return (bool) $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Reads the "checkMode" extension setting: "request" (default) follows the
     * redirect live over HTTP; "database" resolves everything against the
     * pages/sys_redirect tables instead, without firing any request.
     */
    protected function getCheckMode(): string
    {
        try {
            $mode = $this->extensionConfiguration->get('extendredirect', 'checkMode');
        } catch (\Exception) {
            $mode = 'request';
        }

        return $mode === 'database' ? 'database' : 'request';
    }

    /**
     * "database" check mode: resolves the redirect's target purely against the
     * pages/sys_redirect tables, following any further redirect chain the same
     * way, without ever making an HTTP request.
     *
     * - A target with capture-group placeholders ($1, $2, ...) can't be resolved
     *   to one concrete page, so it's left untouched and treated as healthy.
     * - A target whose host doesn't belong to any configured site is genuinely
     *   external - nothing local to check it against, also left alone.
     * - Otherwise the target must resolve to an existing, non-hidden page. If it
     *   does, and that page's slug is itself some other (non-disabled) redirect's
     *   source, the chain is followed the same way until a terminal page is
     *   found. The original redirect's target is then rewritten to a
     *   "t3://page?uid=..." link to that terminal page, flattening the chain.
     *
     * @param array<mixed> $redirect
     *
     * @return array<mixed>
     */
    protected function checkRedirectViaDatabase(array &$redirect): array
    {
        $visited = [(int) $redirect['uid'] => true];
        $target = (string) ($redirect['target'] ?? '');
        $sourceHost = (string) ($redirect['source_host'] ?? '*');
        $hops = 0;
        $finalPageUid = 0;

        while (true) {
            if (str_contains($target, '$')) {
                return ['redirectError' => false, 'redirectCount' => $hops, 'message' => ''];
            }

            $resolution = $this->resolveTargetPage($target, $sourceHost);
            if (!is_array($resolution)) {
                // Either self::TARGET_EXTERNAL (unverifiable, leave alone) or null (not found).
                return $resolution === self::TARGET_EXTERNAL
                    ? ['redirectError' => false, 'redirectCount' => $hops, 'message' => '']
                    : ['redirectError' => true, 'redirectCount' => '-', 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
            }

            $pageUid = $resolution['pageUid'];
            $slug = $resolution['slug'];
            $host = $resolution['host'];
            $finalPageUid = $pageUid;

            $chainRedirect = GeneralUtility::makeInstance(RedirectRepository::class)->findChainingRedirect($slug, $host, (int) $redirect['uid']);
            if ($chainRedirect === null) {
                break;
            }

            $chainUid = (int) $chainRedirect['uid'];
            if (isset($visited[$chainUid]) || $hops >= self::MAX_CHAIN_HOPS) {
                return ['redirectError' => true, 'redirectCount' => $hops, 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.loopRisk')];
            }
            $visited[$chainUid] = true;
            $hops++;

            $target = (string) ($chainRedirect['target'] ?? '');
            $sourceHost = (string) ($chainRedirect['source_host'] ?? '*');
        }

        $redirect['target'] = $this->toPageTypolink($finalPageUid) ?: $redirect['target'];

        return [
            'redirectError' => false,
            'redirectCount' => $hops,
            'message' => $hops > 0 ? $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.chainCorrected') : '',
        ];
    }

    /**
     * Resolves a target string to a page, scoped to whichever site the given
     * source_host implies (unscoped for a wildcard '*' source_host, since that
     * matches every site's tree).
     *
     * @return array{pageUid: int, slug: string, host: string}|string|null
     *     Resolved page info on success, self::TARGET_EXTERNAL for an
     *     unverifiable external target, or null if the target doesn't exist.
     */
    protected function resolveTargetPage(string $target, string $sourceHost): array|string|null
    {
        try {
            $linkDetails = GeneralUtility::makeInstance(LinkService::class)->resolve($target);
        } catch (\Exception) {
            $linkDetails = [];
        }

        if (($linkDetails['type'] ?? null) === LinkService::TYPE_PAGE) {
            $pageUid = $linkDetails['pageuid'] ?? null;
            if (!is_numeric($pageUid)) {
                return null;
            }
            $page = $this->findPageByUid((int) $pageUid);
            if ($page === null) {
                return null;
            }
            $host = $this->findSiteHostForPage((int) $pageUid) ?? $sourceHost;

            return ['pageUid' => (int) $pageUid, 'slug' => (string) $page['slug'], 'host' => $host];
        }

        $host = parse_url($target, PHP_URL_HOST);
        $path = (string) parse_url($target, PHP_URL_PATH);

        $expectedSite = null;
        if (is_string($host)) {
            $expectedSite = $this->findSiteByHost($host);
            if ($expectedSite === null) {
                return self::TARGET_EXTERNAL;
            }
        } elseif ($sourceHost !== '*') {
            $expectedSite = $this->findSiteByHost($sourceHost);
        }

        $page = $this->findPageBySlug($path, $expectedSite);
        if ($page === null) {
            return null;
        }

        $pageUid = (int) $page['uid'];
        $resolvedHost = $expectedSite?->getBase()->getHost() ?? $this->findSiteHostForPage($pageUid) ?? $sourceHost;

        return ['pageUid' => $pageUid, 'slug' => (string) $page['slug'], 'host' => $resolvedHost];
    }

    /**
     * @return array<mixed>|null
     */
    protected function findPageByUid(int $pageUid): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        $page = $queryBuilder
            ->select('uid', 'pid', 'slug', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $page === false ? null : $page;
    }

    /**
     * Finds the page matching a slug. If more than one page shares that slug
     * (only possible across different site trees) and an expected site is
     * given, only a match within that site's own tree counts - a same-named
     * page belonging to a different site is not "the" target.
     *
     * @return array<mixed>|null
     */
    protected function findPageBySlug(string $slug, ?Site $expectedSite): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));

        $candidates = $queryBuilder
            ->select('uid', 'pid', 'slug')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug, ParameterType::STRING)))
            ->executeQuery()
            ->fetchAllAssociative();

        if ($candidates === []) {
            return null;
        }

        if ($expectedSite === null) {
            return $candidates[0];
        }

        foreach ($candidates as $candidate) {
            if ($this->findSiteRootUid((int) $candidate['uid']) === $expectedSite->getRootPageId()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Walks a page's pid chain up to the site root (the page whose own pid is 0).
     */
    protected function findSiteRootUid(int $pageUid): int
    {
        while (true) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $row = $queryBuilder
                ->select('uid', 'pid')
                ->from('pages')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();

            if ($row === false || (int) $row['pid'] === 0) {
                return $pageUid;
            }

            $pageUid = (int) $row['pid'];
        }
    }

    protected function findSiteByHost(string $host): ?Site
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            if (isset($this->getSiteHosts($site)[$host])) {
                return $site;
            }
        }

        return null;
    }

    /**
     * The domains a site answers on - its base and every language with a domain of its own - each with its scheme.
     *
     * @return array<string, string> scheme by host
     */
    protected function getSiteHosts(Site $site): array
    {
        $hosts = [$site->getBase()->getHost() => $site->getBase()->getScheme()];
        foreach ($site->getLanguages() as $language) {
            $host = $language->getBase()->getHost();
            if ($host !== '' && !isset($hosts[$host])) {
                $hosts[$host] = $language->getBase()->getScheme();
            }
        }

        return $hosts;
    }

    protected function findSiteHostForPage(int $pageUid): ?string
    {
        $rootUid = $this->findSiteRootUid($pageUid);

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($site->getRootPageId() === $rootUid) {
                return $site->getBase()->getHost();
            }
        }

        return null;
    }

    /**
     * Check a single resolved URL
     *
     * @param string        $url
     * @param array<mixed> &$redirect
     *
     * @return array<mixed>
     */
    protected function checkSingleUrl(string $url, array &$redirect): array
    {
        $response = $this->redirectFollower($url);

        $redirectCount = 0;
        $maxRedirects = 10;
        $finalLocation = $url;

        while ($response->hasHeader('Location') && $redirectCount < $maxRedirects) {

            // Den Location-Header auslesen (PSR-7 gibt Arrays zurück)
            $newLocation = $response->getHeader('location')[0];

            if (empty($newLocation)) {
                break; // Falls der Header leer ist, abbrechen
            }

            $redirectCount++;
            $finalLocation = $newLocation;

            $response = $this->redirectFollower($newLocation);
        }

        $statusCode = $response->getStatusCode();

        switch (true) {
            case $statusCode === 200:
                // Regardless of how many hops it took to get here, the target should
                // end up as a "t3://page?uid=..." link if it resolves to a page - not
                // just when a multi-hop chain got flattened.
                $message = '';
                $urlPath = (string) parse_url($finalLocation, PHP_URL_PATH);
                $page = $this->getPageIdFromPath($urlPath);
                if (is_array($page) && array_key_exists('uid', $page)) {
                    $correctedTarget = $this->toPageTypolink((int) $page['uid']);
                    if ($correctedTarget && $correctedTarget !== $redirect['target']) {
                        $redirect['target'] = $correctedTarget;
                        $message = $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.chainCorrected');
                    }
                }
                if ($redirectCount > 1) {
                    $redirectCount = 1;
                }
                return ['redirectError' => false, 'redirectCount' => $redirectCount, 'message' => $message];

            case $statusCode === 404:
                return ['redirectError' => true, 'redirectCount' => $redirectCount, 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.notFound')];

            case $redirectCount === $maxRedirects:
                return ['redirectError' => true, 'redirectCount' => $redirectCount, 'message' => $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.loopRisk')];

            default:
                return ['redirectError' => true, 'redirectCount' => '-', 'message' => $statusCode . ' - ' . $GLOBALS['LANG']->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:healthCheck.pageNotFound')];
        }
    }

    /**
     * Resolve the check URL(s) for a redirect, keyed by domain host.
     *
     * A specific source_host resolves to the single matching site. A wildcard
     * ('*') source_host resolves to every configured site, since it is meant
     * to match on any domain.
     *
     * @param array<mixed> $redirect
     *
     * @return array<string, string>
     */
    protected function resolveUrls(array $redirect): array
    {
        $sourceHost = (string) $redirect['source_host'];
        $path = $redirect['source_path'] ?? '';

        // A specific host resolves to exactly its own domain (a site base or a language domain); "*" matches
        // every domain of every site, language domains included.
        $urls = [];
        foreach ($this->findSitesBySourceHost($sourceHost) as $site) {
            foreach ($this->getSiteHosts($site) as $host => $scheme) {
                if ($sourceHost === '*' || $host === $sourceHost) {
                    $urls[$host] = $scheme . '://' . $host . $path;
                }
            }
        }

        return $urls;
    }

    /**
     * Get Page ID from path
     *
     * @param string $path
     *
     * @return array<mixed>|bool
     * @throws Exception
     */
    public function getPageIdFromPath(string $path): array|bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path, ParameterType::STRING)))
            ->executeQuery()
            ->fetchAssociative();

    }

    /**
     * Find the site(s) matching a redirect's source_host.
     *
     * A specific host must match a configured site's domain exactly - no
     * arbitrary fallback to another site if it doesn't. A wildcard ('*')
     * matches every configured site.
     *
     * @param string $sourceHost
     *
     * @return Site[]
     */
    protected function findSitesBySourceHost(string $sourceHost): array
    {
        $sites = $this->siteFinder->getAllSites();

        if ($sourceHost === '*') {
            return $sites;
        }

        foreach ($sites as $site) {
            if (isset($this->getSiteHosts($site)[$sourceHost])) {
                return [$site];
            }
        }

        return [];
    }
}
