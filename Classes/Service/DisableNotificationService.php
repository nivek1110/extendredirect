<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use Nivek1110\Extendredirect\Event\RedirectDisabledEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Announces disabled redirects: always as PSR-14 RedirectDisabledEvent, and - if enabled - by mail
 * and/or to a webhook.
 *
 * Disabled redirects are handed in via queue() as they are logged. Depending on the
 * "notifyMode" extension setting a mail goes out immediately for every redirect ("single")
 * or one summary mail with everything queued is sent on flush() ("digest", the default) - so
 * a bulk run that disables 200 redirects results in one mail, not 200. Sending is off unless
 * "notifyOnDisable" is enabled (it switches mail and webhook), and a failing mail transport or
 * webhook is logged but never breaks the save or check that triggered it. The webhook is called
 * when "notifyWebhookUrl" is set, with the same digest/single rhythm as the mail.
 *
 * A singleton, so the check and the page guard share one queue within a request.
 */
class DisableNotificationService implements SingletonInterface
{
    public const MODE_DIGEST = 'digest';
    public const MODE_SINGLE = 'single';

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @param array<string, mixed> $entry the disable log values of one redirect
     */
    public function queue(array $entry): void
    {
        try {
            $this->eventDispatcher->dispatch(new RedirectDisabledEvent($entry));
        } catch (\Throwable $exception) {
            // A faulty listener of someone else's code must never break the save, the check or the page deletion.
            // @extensionScannerIgnoreLine PSR-3 LoggerInterface::error(), not the core API of the same name
            $this->logger->error('A listener of RedirectDisabledEvent failed: ' . $exception->getMessage(), ['exception' => $exception]);
        }

        if (!$this->isEnabled()) {
            return;
        }

        if ($this->getMode() === self::MODE_SINGLE) {
            $this->send([$entry]);

            return;
        }

        $this->pending[] = $entry;
    }

    /**
     * Sends the summary mail for everything queued so far (digest mode); a no-op otherwise.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $entries = $this->pending;
        $this->pending = [];
        $this->send($entries);
    }

    /**
     * @return list<string> the valid, de-duplicated addresses of a comma or semicolon separated list
     */
    public static function parseRecipients(string $raw): array
    {
        $addresses = [];
        foreach (GeneralUtility::trimExplode(',', str_replace(';', ',', $raw), true) as $candidate) {
            if (GeneralUtility::validEmail($candidate) && !in_array(strtolower($candidate), array_map('strtolower', $addresses), true)) {
                $addresses[] = $candidate;
            }
        }

        return $addresses;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    protected function createEmail(array $entries, string $language): Email
    {
        $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($language);
        $label = static fn(string $key): string => $languageService->sL('LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:' . $key);

        $subject = count($entries) === 1
            ? sprintf($label('mail.subject.single'), ($entries[0]['source_host'] ?? '') . ($entries[0]['source_path'] ?? ''))
            : sprintf($label('mail.subject.digest'), count($entries));

        $email = (new FluidEmail())
            ->format(FluidEmail::FORMAT_BOTH)
            ->setTemplate('RedirectsDisabled')
            ->assignMultiple([
                'entries' => $entries,
                'count' => count($entries),
                'language' => $language,
                'siteName' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '',
            ]);
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $email->setRequest($request);
        }

        return $email->subject($subject);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function send(array $entries): void
    {
        $recipients = self::parseRecipients($this->getSetting('notifyRecipients'));
        $webhookUrl = $this->getSetting('notifyWebhookUrl');
        if ($recipients === [] && $webhookUrl === '') {
            $this->logger->warning('Redirect disable notification is enabled but neither a valid recipient ("notifyRecipients") nor a webhook ("notifyWebhookUrl") is configured.');

            return;
        }

        if ($recipients !== []) {
            $this->sendMail($entries, $recipients);
        }
        if ($webhookUrl !== '') {
            $this->callWebhook($entries, $webhookUrl);
        }
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $recipients
     */
    private function sendMail(array $entries, array $recipients): void
    {
        try {
            // @extensionScannerIgnoreLine own private method, not TypoScriptFrontendController::getLanguage()
            $email = $this->createEmail($entries, $this->getLanguage());
            $email->to(...array_map(static fn(string $address): Address => new Address($address), $recipients));
        } catch (\Throwable $exception) {
            $this->logSendingFailure($exception);

            return;
        }

        // The mail is built now (it needs the request), the slow SMTP transport runs after the response.
        $this->runAfterResponse(function () use ($email): void {
            try {
                $this->mailer->send($email);
            } catch (\Throwable $exception) {
                $this->logSendingFailure($exception);
            }
        });
    }

    private function logSendingFailure(\Throwable $exception): void
    {
        // A broken mail setup must never break the save, the check or the page deletion.
        // @extensionScannerIgnoreLine PSR-3 LoggerInterface::error(), not the core API of the same name
        $this->logger->error('Sending the redirect disable notification failed: ' . $exception->getMessage(), ['exception' => $exception]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function callWebhook(array $entries, string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            $this->logger->warning('The redirect disable webhook is ignored: "notifyWebhookUrl" must be an http(s) URL.');

            return;
        }

        try {
            $body = json_encode($this->buildWebhookPayload($entries), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $exception) {
            $this->logWebhookFailure($exception);

            return;
        }

        // The slow HTTP call runs after the response, so an editor never waits for the endpoint.
        $this->runAfterResponse(function () use ($url, $body): void {
            try {
                $this->postJson($url, $body);
            } catch (\Throwable $exception) {
                $this->logWebhookFailure($exception);
            }
        });
    }

    private function logWebhookFailure(\Throwable $exception): void
    {
        // A broken webhook must never break the save, the check or the page deletion.
        // @extensionScannerIgnoreLine PSR-3 LoggerInterface::error(), not the core API of the same name
        $this->logger->error('Calling the redirect disable webhook failed: ' . $exception->getMessage(), ['exception' => $exception]);
    }

    /**
     * Runs slow work (SMTP, HTTP) once the response is out. Under PHP-FPM the response is finished first
     * (fastcgi_finish_request) and the task runs while the worker is still busy but the browser is not waiting.
     * In the CLI (command, Scheduler) nobody waits, and without FPM there is no way to finish the response
     * early: both run the task at once.
     *
     * @param callable(): void $task must catch its own errors
     */
    protected function runAfterResponse(callable $task): void
    {
        if (PHP_SAPI === 'cli' || !function_exists('fastcgi_finish_request')) {
            $task();

            return;
        }

        register_shutdown_function(static function () use ($task): void {
            // First: send the finished response to the browser and close the connection.
            fastcgi_finish_request();
            $task();
        });
    }

    /**
     * "text" ({"text": "..."}) is understood by Slack, Mattermost, Rocket.Chat and similar incoming
     * webhooks; "json" is a structured payload for your own endpoint.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(array $entries): array
    {
        $siteName = (string) ($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '');

        if ($this->getSetting('notifyWebhookFormat') === 'json') {
            return [
                'event' => 'redirects_disabled',
                'site' => $siteName,
                'count' => count($entries),
                'redirects' => array_map(static fn(array $entry): array => [
                    'redirect_uid' => (int) ($entry['redirect_uid'] ?? 0),
                    'source_host' => (string) ($entry['source_host'] ?? ''),
                    'source_path' => (string) ($entry['source_path'] ?? ''),
                    'target' => (string) ($entry['target'] ?? ''),
                    'status_code' => (string) ($entry['status_code'] ?? ''),
                    'reason' => (string) ($entry['reason'] ?? ''),
                ], $entries),
            ];
        }

        $lines = [sprintf(
            '%s%d %s disabled',
            $siteName !== '' ? $siteName . ': ' : '',
            count($entries),
            count($entries) === 1 ? 'redirect was' : 'redirects were'
        )];
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '- %s%s -> %s (%s)',
                $entry['source_host'] ?? '',
                $entry['source_path'] ?? '',
                $entry['target'] ?? '',
                $entry['reason'] ?? ''
            );
        }

        return ['text' => implode("\n", $lines)];
    }

    /**
     * Seam for tests; throws if the endpoint cannot be reached or answers with an error status.
     */
    protected function postJson(string $url, string $body): void
    {
        $response = GeneralUtility::makeInstance(RequestFactory::class)->request($url, 'POST', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
            'timeout' => 5,
            'connect_timeout' => 3,
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('HTTP status ' . $response->getStatusCode());
        }
    }

    private function isEnabled(): bool
    {
        return in_array($this->getSetting('notifyOnDisable'), ['1', 'true', 'on'], true);
    }

    private function getMode(): string
    {
        return $this->getSetting('notifyMode') === self::MODE_SINGLE ? self::MODE_SINGLE : self::MODE_DIGEST;
    }

    /**
     * "default" is TYPO3's key for English labels.
     */
    private function getLanguage(): string
    {
        return $this->getSetting('notifyLanguage') === 'de' ? 'de' : 'default';
    }

    private function getSetting(string $name): string
    {
        try {
            $configuration = $this->extensionConfiguration->get('extendredirect');
        } catch (\Exception) {
            return '';
        }

        return is_array($configuration) ? trim((string) ($configuration[$name] ?? '')) : '';
    }
}
