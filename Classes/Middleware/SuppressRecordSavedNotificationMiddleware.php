<?php

namespace Nivek1110\Extendredirect\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;

/**
 * FormEngine's own "Record saved" toast (EditDocumentController) is redundant on
 * sys_redirect saves: DataHandlerHook already reports the outcome of the health check
 * (disabled/adjusted/ok), and showing both is confusing when the two contradict each
 * other (e.g. "Record saved" next to "Redirect disabled"). EditDocumentController has
 * no hook to opt out of that message, so it is stripped from the backend user's
 * notification-queue session data after the request has been processed, once the
 * record/edit or record/commit call already touched sys_redirect.
 */
class SuppressRecordSavedNotificationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->isSysRedirectSave($request)) {
            return $response;
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return $response;
        }

        $sessionMessages = $backendUser->getSessionData(FlashMessageQueue::NOTIFICATION_QUEUE);
        if (!is_array($sessionMessages) || $sessionMessages === []) {
            return $response;
        }

        $languageService = $GLOBALS['LANG'] ?? null;
        $recordSavedTitles = [
            $languageService?->sL('LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:notification.record_saved.title.singular'),
            $languageService?->sL('LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:notification.record_saved.title.plural'),
        ];

        $filteredMessages = array_values(array_filter(
            $sessionMessages,
            static function ($encodedMessage) use ($recordSavedTitles) {
                $decodedMessage = is_string($encodedMessage) ? json_decode($encodedMessage, true) : null;
                $title = $decodedMessage['title'] ?? null;
                return !in_array($title, $recordSavedTitles, true);
            }
        ));

        if ($filteredMessages !== $sessionMessages) {
            $backendUser->setAndSaveSessionData(FlashMessageQueue::NOTIFICATION_QUEUE, $filteredMessages);
        }

        return $response;
    }

    protected function isSysRedirectSave(ServerRequestInterface $request): bool
    {
        $data = $request->getParsedBody()['data'] ?? $request->getQueryParams()['data'] ?? [];
        return is_array($data) && array_key_exists('sys_redirect', $data);
    }
}
