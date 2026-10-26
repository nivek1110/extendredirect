# TYPO3 Extension `extendredirect`

## Introduction

### What does it do?

`extendredirect` turns TYPO3's built-in redirects module into a self-maintaining system. Instead of
redirects silently rotting once their target moves or disappears, this extension watches them,
disables the ones that break, straightens out the ones that don't have to be broken, and gives
editors a clear audit trail of what happened and why — directly in the existing "Redirects" backend
module, no new UI to learn.

### Why you'd want this

Redirects normally just sit in the database until someone notices a 404 report and goes digging.
`extendredirect` catches that automatically: broken targets get disabled the moment they're detected
(on save, and in bulk via a scheduled check), so dead links stop reaching visitors instead of piling
up unnoticed.

## Features

- **Automatic health check** — every redirect's target is verified: followed live over HTTP like a
  browser would, or resolved purely against the `pages`/`sys_redirect` tables without firing a single
  request (your choice, see [Configuration](#configuration)). Redirects whose target no longer
  resolves are disabled automatically.
- **Self-healing targets** — a target that resolves to a TYPO3 page is rewritten to the canonical
  `t3://page?uid=…` link, and a chain of several redirects hopping to each other (up to 10 hops) is
  flattened straight to the final page, so it isn't repeatedly re-checked.
- **Re-checked on every edit** — the moment you change a redirect's target, it's automatically
  re-enabled and health-checked again, so a fix is confirmed working immediately instead of waiting
  for the next scheduled run.
- **Page delete/hide protection** — deleting or hiding a page automatically disables every redirect
  that pointed at it, with a logged reason, instead of leaving them dangling.
- **Broken-redirect filter** — the Redirects module's filter bar gains a "broken redirect" option, so
  you can list exactly the redirects that need attention.
- **Disable log** — a dedicated log view lists every redirect the health check (or the page-guard)
  has disabled, with the reason, source, target and timestamp — so nothing gets disabled silently.
  Entries can be deleted individually or all at once. A "Check now" button per entry re-checks that
  redirect on demand: a healthy one is enabled again, a broken one stays disabled (redirects you
  disabled by hand are never touched). A "Suggested target" column proposes up to three existing pages
  that resemble the broken target (or its nearest existing parent page); "Apply" makes one the new
  target and re-checks the redirect.
- **Last checked** — the overview shows when each redirect was last checked and can filter for
  never-checked redirects or ones not checked for 1, 7 or 30 days, so you can see whether the
  Scheduler actually runs.
- **CSV import** — bulk-import redirects from a semicolon-separated CSV file straight from the
  module's toolbar, with built-in validation: unknown source hosts and duplicate source paths are
  rejected/skipped and reported back, and page-resolving targets are converted to canonical page
  links on the way in. An "Export redirects" button downloads all redirects as CSV in the same
  format, so an export can be edited and imported again.
- **CLI / Scheduler command** — `extendredirect:checkredirects` runs the health check over every
  redirect in bulk, so you can schedule it via TYPO3's Scheduler or cron instead of relying purely on
  the on-edit check.
  Options: `--dry-run` only reports which redirects would be disabled and changes nothing;
  `--delay=<ms>` pauses after each checked redirect (rate limit for `request` mode);
  `--max-runtime=<seconds>` stops starting new checks once the budget is used up and reports how
  many redirects were left unchecked; `--limit=<n>` checks at most n redirects per run. Redirects are
  processed never-checked first, then least recently checked (`last_checked`), so limited runs rotate
  through all of them. Redirects you disabled by hand are never re-enabled.
- **Disable log cleanup** — `extendredirect:cleanuplog --days=30` deletes disable log entries older
  than the given number of days (default 30). Schedulable like the core "Table garbage collection"
  task; the `days` option becomes a field in the Scheduler task form.
- **Optional email notification** — when a redirect is disabled (by the health check or the page
  protection) a mail can be sent to a list of recipients, either as one summary mail per run or as one
  mail per redirect. Off by default, see [Configuration](#configuration).
- **Webhook and PSR-14 event** — the same notification can be posted as JSON to a webhook (a chat
  message for Slack/Mattermost/Rocket.Chat, or structured JSON for your own endpoint). Independent of
  that, every disabled redirect is dispatched as `Nivek1110\Extendredirect\Event\RedirectDisabledEvent`,
  so your own code can react to it.
- **No duplicate/confusing notifications** — the generic "Record saved" toast is suppressed on
  `sys_redirect` saves so it doesn't contradict the health-check's own outcome message (e.g.
  "Redirect disabled").

## Installation

### Composer

```
composer require nivek1110/extendredirect
```

### TER

Alternatively, download the extension from the
[TYPO3 Extension Repository](https://extensions.typo3.org/extension/extendredirect) and install it
via the Extension Manager.

## Configuration

Extension configuration (Admin Tools → Settings → Extension Configuration → `extendredirect`):

| Setting     | Type    | Description                                                                                                     |
|-------------|---------|-------------------------------------------------------------------------------------------------------------------|
| `checkMode` | options | `request` (default): follow the redirect live over HTTP, like a browser. `database`: resolve directly against the `pages`/`sys_redirect` tables without firing any request. |

Notification settings (same place, category *notifications*):

| Setting            | Type    | Description |
|--------------------|---------|-------------|
| `notifyOnDisable`  | boolean | Notify when a redirect is disabled (switches mail and webhook). Default: off. |
| `notifyRecipients` | string  | Comma or semicolon separated addresses. Invalid ones are ignored; without a valid address nothing is sent. The sender is TYPO3's default mail sender (`$GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']`). |
| `notifyMode`       | options | `digest` (default): one mail listing every redirect disabled during one check, command run or page change. `single`: one mail per redirect, sent immediately. |
| `notifyLanguage`   | options | `en` (default) or `de`. |
| `notifyWebhookUrl` | string  | Optional http(s) URL that receives a JSON `POST` with the same rhythm as the mail. Empty = no webhook. Calls time out after 5 seconds; failures are logged, never fatal. |
| `notifyWebhookFormat` | options | `text` (default): `{"text": "..."}` as understood by Slack, Mattermost and Rocket.Chat incoming webhooks. `json`: `{"event": "redirects_disabled", "site": "...", "count": 1, "redirects": [{"redirect_uid", "source_host", "source_path", "target", "status_code", "reason"}]}`. |

The webhook URL is configured by administrators only; it is not validated beyond being http(s), so do not
point it at internal services you do not control.

To react in your own code, listen to the event (it is dispatched for every disabled redirect,
regardless of these settings):

```php
use Nivek1110\Extendredirect\Event\RedirectDisabledEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

#[AsEventListener]
final class MyListener
{
    public function __invoke(RedirectDisabledEvent $event): void
    {
        // $event->getRedirectUid(), getSourceHost(), getSourcePath(), getTarget(), getReason() ...
    }
}
```

Nothing is sent by `--dry-run`, and a failing mail transport is only logged — it never breaks a save,
a check or a page deletion. The Scheduler task or CLI run needs a working mail configuration (`MAIL`) like
any other TYPO3 mail.

> **`request` mode needs a working site base URL.** The check builds the URL to follow from each
> site's configured base (Site Configuration → Base). Without a reachable, correctly configured base
> URL there's nothing to request, and the health check can't verify anything — use `database` mode
> instead if your site isn't reachable over HTTP (e.g. local/offline environments).

## Usage

Open the **Redirects** backend module (Link Management → Redirects) as usual — it now includes the
broken-redirect filter, CSV import and disable log. To run the health check in bulk, schedule the
`Check Redirects` command via the TYPO3 Scheduler, or run it manually:

```
vendor/bin/typo3 extendredirect:checkredirects
```

## Requirements

- PHP **8.3+**
- TYPO3 **13.4 LTS** or **14.3 LTS**

## Contributing

A reproducible DDEV demo instance, the test workflow, and the coding/PR conventions are documented
in [CONTRIBUTING.md](CONTRIBUTING.md). In short:

```bash
ddev start && ddev setup && ddev demo   # demo at https://extendredirect.ddev.site/ (user admin, password printed by `ddev demo`)
ddev test all && ddev lint
```

## License

This extension is released under the [GNU General Public License 2.0 (or later)](LICENSE).
