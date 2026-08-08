<?php

namespace TWT\TwtRedirect\Hooks;

use Doctrine\DBAL\Result;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TWT\TwtRedirect\Service\HealthCheckService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DependencyInjection\LoggerInterfacePass;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Redirects\Hooks\DataHandlerCacheFlushingHook;
use TYPO3\CMS\Redirects\Service\RedirectService;

class DataHandlerHook implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param string $status
     * @param string $table
     * @param string|int $id
     * @param array<mixed> $fieldArray
     * @param DataHandler $dataHandler
     * @return void
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
     * @param string $status
     * @param string $table
     * @param string|int $id
     * @param array<mixed> $fieldArray
     * @param DataHandler $dataHandler
     * @return void
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler &$dataHandler): void
    {
        if ($table === 'sys_redirect' && $status === 'update') {
            if ((array_key_exists('disabled', $fieldArray) && $fieldArray['disabled'] === '0') || empty($fieldArray) || array_key_exists('target', $fieldArray)) {
                $this->checkRedirect((int)$id, $dataHandler);
            }
        }

        if ($table === 'sys_redirect' && $status === 'new') {
            $this->checkRedirect((int)$dataHandler->substNEWwithIDs[$id], $dataHandler);
        }
    }

    protected function generateMessage(string $message = 'Diese Weiterleitung erstellt eine Endlosschleife', string $messageHeader = 'Weiterleitung deaktiviert', ContextualFeedbackSeverity $box = ContextualFeedbackSeverity::ERROR): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $messageHeader,
            $box,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $defaultFlashMessageQueue->enqueue($flashMessage);
    }

    protected function checkRedirect(int $id, DataHandler $dataHandler): void
    {
        $redirect = $this->findByUid($id);
        $dataHandlerCacheHook = GeneralUtility::makeInstance(DataHandlerCacheFlushingHook::class);
        $dataHandlerCacheHook->rebuildRedirectCacheIfNecessary(['table' => 'sys_redirect', 'uid' => $id], $dataHandler);
        $healthCheckService = GeneralUtility::makeInstance(HealthCheckService::class);
        $message = $healthCheckService->runHealthCheck($redirect);
        if (is_array($message) && array_key_exists('redirectError', $message) && $message['redirectError']) {
            $this->generateMessage($message['message']);
        }

        if (is_array($message) && !$message['redirectError'] && $message['message']) {
            $this->generateMessage($message['message'], 'Weiterleitung angepasst', ContextualFeedbackSeverity::INFO);
        }
    }

    /**
     * @param int|null $uid
     * @return array<mixed>|bool
     * @throws \Doctrine\DBAL\Exception
     */
    protected function findByUid(?int $uid): array|bool
    {
        if ($uid === null) {
            return false;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )->executeQuery();

        return $result->fetchAssociative();
    }

}
