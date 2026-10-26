<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Event;

/**
 * PSR-14 event, dispatched after a redirect was disabled by the health check (on save, via the
 * command or the scheduler task) or by the page delete/hide guard, and logged in the disable log.
 *
 * Purely informational: the redirect is already disabled when listeners run, and the event
 * is dispatched regardless of the notification settings. In "digest" mode it is still
 * dispatched once per redirect, as soon as that redirect is disabled.
 */
final class RedirectDisabledEvent
{
    /**
     * @param array<string, mixed> $entry the disable log values of the redirect
     */
    public function __construct(private readonly array $entry) {}

    public function getRedirectUid(): int
    {
        return (int) ($this->entry['redirect_uid'] ?? 0);
    }

    public function getSourceHost(): string
    {
        return (string) ($this->entry['source_host'] ?? '');
    }

    public function getSourcePath(): string
    {
        return (string) ($this->entry['source_path'] ?? '');
    }

    public function getTarget(): string
    {
        return (string) ($this->entry['target'] ?? '');
    }

    /**
     * The HTTP status or check result the redirect failed with, as logged ('-' if there is none).
     */
    public function getStatusCode(): string
    {
        return (string) ($this->entry['status_code'] ?? '');
    }

    public function getReason(): string
    {
        return (string) ($this->entry['reason'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntry(): array
    {
        return $this->entry;
    }
}
