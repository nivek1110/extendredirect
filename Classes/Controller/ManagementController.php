<?php

namespace TWT\TwtRedirect\Controller;

use Doctrine\DBAL\Result;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TWT\TwtRedirect\Repository\DisableLogRepository;
use TWT\TwtRedirect\Repository\RedirectRepository;
use TWT\TwtRedirect\Service\HealthCheckService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Exception;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Repository\Demand;

class ManagementController extends \TYPO3\CMS\Redirects\Controller\ManagementController
{
    private const ALLOWED_STATUS_CODES = [301, 302, 303, 307, 308];


    public function __construct(UriBuilder $uriBuilder, IconFactory $iconFactory, \TYPO3\CMS\Redirects\Repository\RedirectRepository $redirectRepository, ModuleTemplateFactory $moduleTemplateFactory, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($uriBuilder, $iconFactory, $redirectRepository, $moduleTemplateFactory, $eventDispatcher);
    }

    public function handleImportRequest(ServerRequest $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $this->registerDocHeaderButtonsBack($view, (string)$request->getAttribute('normalizedParams')?->getRequestUri());

        return $view->renderResponse('Management/Import');
    }

    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $this->registerDocHeaderButtonsBack($view, (string)$request->getAttribute('normalizedParams')?->getRequestUri());

        if (array_key_exists('redirect', $request->getUploadedFiles())) {
            /* @var UploadedFile $file */
            $file = $request->getUploadedFiles()['redirect'];
            $this->importRedirect($file->getTemporaryFileName(), $view);
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
        $this->registerDocHeaderButtonsBack($view, (string)$request->getAttribute('normalizedParams')?->getRequestUri());

        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);

        $itemsPerPage = 20;
        $count = $logRepository->countAll();
        $numberOfPages = (int)max(1, ceil($count / $itemsPerPage));
        $currentPage = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        if ($currentPage > $numberOfPages) {
            $currentPage = $numberOfPages;
        }
        $offset = ($currentPage - 1) * $itemsPerPage;

        $view->assignMultiple([
            'logs' => $logRepository->findPaginated($itemsPerPage, $offset),
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

        if (!empty($parameters['deleteAll'])) {
            $logRepository->deleteAll();
            $this->addFlashMessage('Alle Log-Einträge wurden gelöscht.', 'Log geleert');
        } elseif (!empty($parameters['logUid'])) {
            $logRepository->deleteByUid((int)$parameters['logUid']);
            $this->addFlashMessage('Der Log-Eintrag wurde gelöscht.', 'Eintrag gelöscht');
        }

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        return new RedirectResponse((string)$uriBuilder->buildUriFromRoute('redirect_disable_log'));
    }

    protected function importRedirect(string $uploadedFile, ModuleTemplate $view): void
    {
        $counter = 0;
        $imported = 0;
        $ignoreCounter = 0;

        $file = fopen($uploadedFile, 'r');
        $flag = true;
        $header = [];

        if (is_resource($file)) {

            while (($data = fgetcsv($file, 9999, ';')) !== false) {
                if ($flag) {
                    $header = $data;
                    $flag = false;
                    continue;
                }
                $headerCount = count($header);
                $dataCount = count($data);
                if ($headerCount > $dataCount) {
                    $fill = $headerCount - $dataCount;
                    for ($i = 0; $i < $fill; $i++) {
                        $data[] = '';
                    }
                }
                $data = array_combine($header, $data);
                $counter++;

                if (!isset($data['source_path'], $data['target'], $data['target_statuscode'])) {
                    $this->addFlashMessage('Daten konnten nicht importiert werden CSV Datei ist fehlerhaft', 'Fehlerhafte Datei', ContextualFeedbackSeverity::ERROR);
                    break;
                }

                if ($this->findRedirect($data['source_path'])) {
                    $ignoreCounter++;
                    continue;
                }


                if (!in_array((int)$data['target_statuscode'], self::ALLOWED_STATUS_CODES, true)) {
                    $data['target_statuscode'] = 308;
                } else {
                    $data['target_statuscode'] = (int)$data['target_statuscode'];
                }

                $data['source_host'] = '*';
                $data['pid'] = '0';

                $healthCheckService = GeneralUtility::makeInstance(HealthCheckService::class);
                $page = $healthCheckService->getPageIdFromPath($data['target']);
                if ($page && is_array($page) && array_key_exists('uid', $page)) {
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


        $view->assignMultiple([
            'counter' => $counter,
            'imported' => $imported,
            'ignoreCounter' => $ignoreCounter
        ]);

    }

    protected function findRedirect(string $sourcePath): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath)),
            )->executeQuery();

        if (!$result instanceof Result) {
            return false;
        }

        if ($result->fetchAllAssociative()) {
            return true;
        }

        return false;
    }

    /**
     * Create document header buttons
     */
    protected function registerDocHeaderButtons(ModuleTemplate $view, string $requestUri): void
    {
        parent::registerDocHeaderButtons($view, $requestUri);


        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        // Import
        $newRecordButton = $buttonBar->makeLinkButton()
            ->setHref((string)$uriBuilder->buildUriFromRoute(
                'record_import',
                [
                    'edit' => ['sys_redirect' => ['import'],
                    ],
                    'returnUrl' => (string)$uriBuilder->buildUriFromRoute('site_redirects'),
                ]
            ))
            ->setTitle('Import redirect')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-add', Icon::SIZE_SMALL));
        $buttonBar->addButton($newRecordButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

        // Disable log
        $logButton = $buttonBar->makeLinkButton()
            ->setHref((string)$uriBuilder->buildUriFromRoute('redirect_disable_log'))
            ->setTitle('Deaktivierungs-Logs')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-list-alternative', Icon::SIZE_SMALL));
        $buttonBar->addButton($logButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

    }

    protected function registerDocHeaderButtonsBack(ModuleTemplate $view, string $requestUri): void
    {
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $closeButton = $buttonBar->makeLinkButton()
            ->setHref((string)$uriBuilder->buildUriFromRoute('site_redirects'))
            ->setClasses('t3js-editform-close')
            ->setTitle($this->getLanguageService()->sL(
                'LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:rm.closeDoc'
            ))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-close', Icon::SIZE_SMALL));

        $buttonBar->addButton($closeButton, ButtonBar::BUTTON_POSITION_LEFT);
    }

    /**
     * Creates a Message object and adds it to the FlashMessageQueue.
     *
     * @param string $messageBody The message
     * @param string $messageTitle Optional message title
     * @param ContextualFeedbackSeverity $severity Optional severity
     * @param bool $storeInSession Optional, defines whether the message should be stored in the session (default) or not
     * @throws Exception
     * @see \TYPO3\CMS\Core\Messaging\FlashMessage
     */
    public function addFlashMessage($messageBody, $messageTitle = '', ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK, $storeInSession = true): void
    {
        if (!is_string($messageBody)) {
            throw new \InvalidArgumentException('The message body must be of type string, "' . gettype($messageBody) . '" given.', 1243258395);
        }
        /* @var \TYPO3\CMS\Core\Messaging\FlashMessage $flashMessage */
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            (string)$messageBody,
            (string)$messageTitle,
            $severity,
            $storeInSession
        );

        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }

    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
    }

    protected function preparePagination(Demand $demand, bool $createCountQuery = false): array
    {
        $redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
        $count = $redirectRepository->countRedirectsByRbDemand($demand);
        $numberOfPages = ceil($count / $demand->getLimit());
        $endRecord = $demand->getOffset() + $demand->getLimit();
        if ($endRecord > $count) {
            $endRecord = $count;
        }

        $pagination = [
            'current' => $demand->getPage(),
            'numberOfPages' => $numberOfPages,
            'hasLessPages' => $demand->getPage() > 1,
            'hasMorePages' => $demand->getPage() < $numberOfPages,
            'startRecord' => $demand->getOffset() + 1,
            'endRecord' => $endRecord,
        ];
        if ($pagination['current'] < $pagination['numberOfPages']) {
            $pagination['nextPage'] = $pagination['current'] + 1;
        }
        if ($pagination['current'] > 1) {
            $pagination['previousPage'] = $pagination['current'] - 1;
        }
        return $pagination;
    }
}
