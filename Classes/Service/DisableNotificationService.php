<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optionally tells the configured recipients that redirects were disabled.
 *
 * Disabled redirects are handed in via queue() as they are logged. Depending on the
 * "notifyMode" extension setting a mail goes out immediately for every redirect ("single")
 * or one summary mail with everything queued is sent on flush() ("digest", the default) - so
 * a bulk run that disables 200 redirects results in one mail, not 200. Sending is off unless
 * "notifyOnDisable" is enabled, and a failing mail transport is logged but never breaks the
 * save or check that triggered it.
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
    ) {}

    /**
     * @param array<string, mixed> $entry the disable log values of one redirect
     */
    public function queue(array $entry): void
    {
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
        if ($recipients === []) {
            $this->logger->warning('Redirect disable notification is enabled but no valid recipient is configured in "notifyRecipients".');

            return;
        }

        try {
            // @extensionScannerIgnoreLine own private method, not TypoScriptFrontendController::getLanguage()
            $email = $this->createEmail($entries, $this->getLanguage());
            $email->to(...array_map(static fn(string $address): Address => new Address($address), $recipients));
            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            // A broken mail setup must never break the save, the check or the page deletion.
            // @extensionScannerIgnoreLine PSR-3 LoggerInterface::error(), not the core API of the same name
            $this->logger->error('Sending the redirect disable notification failed: ' . $exception->getMessage(), ['exception' => $exception]);
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
