<?php

namespace Nivek1110\Extendredirect\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Service\RedirectCsvExportService;
use Nivek1110\Extendredirect\Service\RedirectCsvImportService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ManagementController extends \TYPO3\CMS\Redirects\Controller\ManagementController
{
    // No own constructor: the core parent's constructor signature differs between
    // TYPO3 13 (5 args) and 14 (7 args, adds ComponentFactory + ModulePaginationService).
    // Inheriting it directly keeps this class buildable on both without duplicating
    // whichever param list the installed core version happens to require.

    public function handleImportRequest(ServerRequest $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $this->registerDocHeaderButtonsBack($view, (string) $request->getAttribute('normalizedParams')?->getRequestUri());

        return $view->renderResponse('Management/Import');
    }

    /**
     * Downloads all redirects as a CSV file in the format of the import.
     */
    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->canListRedirects()) {
            return new Response('php://temp', 403);
        }

        $response = new Response();
        $response->getBody()->write(GeneralUtility::makeInstance(RedirectCsvExportService::class)->export());

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="redirects-' . date('Y-m-d') . '.csv"')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $this->registerDocHeaderButtonsBack($view, (string) $request->getAttribute('normalizedParams')?->getRequestUri());

        if (array_key_exists('redirect', $request->getUploadedFiles())) {
            /* @var UploadedFile $file */
            $file = $request->getUploadedFiles()['redirect'];
            $options = (array) ($request->getParsedBody() ?? []);
            $this->importRedirect($file->getTemporaryFileName(), $view, !empty($options['overwrite']), !empty($options['dryRun']));
        }
        return $view->renderResponse('Management/Import');
    }

    /**
     * Shows the backend list with the reasons why redirects were deactivated
     * by the scheduler health check task.
     */
    public function handleLogRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $this->registerDocHeaderButtonsBack($view, (string) $request->getAttribute('normalizedParams')?->getRequestUri());

        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);

        $itemsPerPage = 20;
        $count = $logRepository->countAll();
        $numberOfPages = (int) max(1, ceil($count / $itemsPerPage));
        $currentPage = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        if ($currentPage > $numberOfPages) {
            $currentPage = $numberOfPages;
        }
        $offset = ($currentPage - 1) * $itemsPerPage;

        $logs = $logRepository->findPaginated($itemsPerPage, $offset);
        $view->assignMultiple([
            'logs' => $logs,
            'pagination' => [
                'current' => $currentPage,
                'numberOfPages' => $numberOfPages,
                'hasLessPages' => $currentPage > 1,
                'hasMorePages' => $currentPage < $numberOfPages,
                'previousPage' => $currentPage - 1,
                'nextPage' => $currentPage + 1,
                'startRecord' => $count > 0 ? $offset + 1 : 0,
                'endRecord' => min($offset + $itemsPerPage, $count),
                'count' => $count,
            ],
        ]);

        return $view->renderResponse('Management/Log');
    }

    /**
     * Deletes either a single log entry (?logUid=<uid>) or all log entries
     * (?deleteAll=1) and redirects back to the log list.
     */
    public function deleteLogAction(ServerRequestInterface $request): ResponseInterface
    {
        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);
        $parameters = $request->getQueryParams();

        $languageService = $this->getLanguageService();
        if (!empty($parameters['deleteAll'])) {
            $logRepository->deleteAll();
            $this->addFlashMessage(
                $languageService->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.logsDeleted.message'),
                $languageService->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.logsDeleted.title')
            );
        } elseif (!empty($parameters['logUid'])) {
            $logRepository->deleteByUid((int) $parameters['logUid']);
            $this->addFlashMessage(
                $languageService->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.logDeleted.message'),
                $languageService->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:flashMessage.logDeleted.title')
            );
        }

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        return new RedirectResponse((string) $uriBuilder->buildUriFromRoute('redirect_disable_log'));
    }

    protected function importRedirect(string $uploadedFile, ModuleTemplate $view, bool $overwrite = false, bool $dryRun = false): void
    {
        $result = GeneralUtility::makeInstance(RedirectCsvImportService::class)->importFromCsvFile($uploadedFile, $overwrite, $dryRun);

        foreach ($result['messages'] as $flashMessage) {
            $this->addFlashMessage($flashMessage['message'], $flashMessage['title'], $flashMessage['severity']);
        }

        $view->assignMultiple([
            'counter' => $result['counter'],
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'ignoreCounter' => $result['ignoreCounter'],
            'dryRun' => $result['dryRun'],
            'overwrite' => $overwrite,
            'rows' => $result['rows'],
            'rowsTruncated' => $result['rowsTruncated'],
            'hasRows' => $result['counter'] > 0,
        ]);
    }

    /**
     * Create document header buttons
     *
     * TYPO3 13.4's core declares (ModuleTemplate $view, string $requestUri), 14.x only ($view).
     * The optional second parameter keeps this override compatible with both; the extra
     * argument is simply ignored by the 14.x parent.
     */
    protected function registerDocHeaderButtons(ModuleTemplate $view, string $requestUri = ''): void
    {
        // The 14.x parent takes one argument, the 13.4 one requires the second.
        // @phpstan-ignore arguments.count
        parent::registerDocHeaderButtons($view, $requestUri);

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $moduleRoute = $this->redirectsModuleRouteIdentifier();

        // Import
        $importButton = $this->createLinkButton($buttonBar)
            ->setHref((string) $uriBuilder->buildUriFromRoute(
                'record_import',
                [
                    'edit' => ['sys_redirect' => ['import'],
                    ],
                    'returnUrl' => (string) $uriBuilder->buildUriFromRoute($moduleRoute),
                ]
            ))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:button.import'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-add', IconSize::SMALL));
        $buttonBar->addButton($importButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

        // Export
        $exportButton = $this->createLinkButton($buttonBar)
            ->setHref((string) $uriBuilder->buildUriFromRoute('redirect_export'))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:button.export'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
        $buttonBar->addButton($exportButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

        // Disable log
        $logButton = $this->createLinkButton($buttonBar)
            ->setHref((string) $uriBuilder->buildUriFromRoute('redirect_disable_log'))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:button.disableLog'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-list-alternative', IconSize::SMALL));
        $buttonBar->addButton($logButton, ButtonBar::BUTTON_POSITION_LEFT, 10);
    }

    protected function registerDocHeaderButtonsBack(ModuleTemplate $view, string $requestUri): void
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $closeUrl = (string) $uriBuilder->buildUriFromRoute($this->redirectsModuleRouteIdentifier());

        $closeButton = $this->createCloseLinkButton($buttonBar, $closeUrl);
        // This page (Import/Log) is itself a bare route rendered inside
        // #typo3-contentIframe. $closeUrl is a full module URL, which always renders
        // the whole backend shell (topbar + sidebar + its own content iframe) - loaded
        // without target="_top" it nests a second shell inside the first instead of
        // navigating the real top-level window. Verified against TYPO3 14; keep it.
        // TYPO3 13's LinkButton has no setAttributes() (only GenericButton does), so it is
        // only set where the button supports it.
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($closeButton, 'setAttributes')) {
            $closeButton->setAttributes(['target' => '_top']);
        }

        $buttonBar->addButton($closeButton, ButtonBar::BUTTON_POSITION_LEFT);
    }

    /**
     * TYPO3 14's core ManagementController constructor gained a ComponentFactory
     * dependency (this class inherits whichever constructor the installed core provides,
     * see the class-level note above) - route new buttons through it when present, since
     * that's the current core idiom, falling back to the still-supported
     * ButtonBar::makeLinkButton() on TYPO3 13.
     */
    protected function createLinkButton(ButtonBar $buttonBar): LinkButton
    {
        // Only ever "always true" when analysed against a TYPO3 14 install (which has
        // this property) - on TYPO3 13 (no componentFactory) it correctly evaluates false.
        // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue
        if (property_exists($this, 'componentFactory') && $this->componentFactory instanceof ComponentFactory) {
            return $this->componentFactory->createLinkButton();
        }
        // @extensionScannerIgnoreLine TYPO3 13 fallback; on 14 the ComponentFactory branch above is used
        return $buttonBar->makeLinkButton();
    }

    /**
     * @see createLinkButton()
     */
    protected function createCloseLinkButton(ButtonBar $buttonBar, string $closeUrl): LinkButton
    {
        // @phpstan-ignore function.alreadyNarrowedType, instanceof.alwaysTrue
        if (property_exists($this, 'componentFactory') && $this->componentFactory instanceof ComponentFactory) {
            return $this->componentFactory->createCloseButton($closeUrl);
        }
        // @extensionScannerIgnoreLine TYPO3 13 fallback; on 14 the ComponentFactory branch above is used
        return $buttonBar->makeLinkButton()
            ->setHref($closeUrl)
            ->setTitle($this->getLanguageService()->sL(
                'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:rm.closeDoc'
            ))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-close', IconSize::SMALL));
    }

    /**
     * TYPO3 14 renamed the core "Redirects" module identifier from `site_redirects`
     * (parent `site`) to `redirects` (parent `link_management`), keeping `site_redirects`
     * only as a route alias (Feature #107628) - mirrors Configuration/Backend/Modules.php,
     * which registers this extension's override under whichever id matches, and
     * AddFilterEventListener, which assigns the same identifier to the Overview template.
     */
    protected function redirectsModuleRouteIdentifier(): string
    {
        return (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects';
    }

    /**
     * Creates a Message object and adds it to the FlashMessageQueue. This
     * (non-Extbase) controller base has no addFlashMessage() of its own.
     *
     * @see \TYPO3\CMS\Core\Messaging\FlashMessage
     */
    public function addFlashMessage(string $messageBody, string $messageTitle = '', ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK, bool $storeInSession = true): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $messageBody,
            $messageTitle,
            $severity,
            $storeInSession
        );

        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }
}
