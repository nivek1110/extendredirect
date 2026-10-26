<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\MailerInterface;

/**
 * Test double for TYPO3's mailer that only records what would have been sent.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    /** When set, send() throws this instead of recording. */
    public ?\Throwable $failWith = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = $message;
    }

    /**
     * @return list<Email>
     */
    public function emails(): array
    {
        return array_values(array_filter($this->sent, static fn(RawMessage $m): bool => $m instanceof Email));
    }

    public function getSentMessage(): ?SentMessage
    {
        return null;
    }

    public function getTransport(): TransportInterface
    {
        return new NullTransport();
    }

    public function getRealTransport(): TransportInterface
    {
        return new NullTransport();
    }
}
