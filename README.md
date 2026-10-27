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
- **Automatic re-enabling ("healing")** — a redirect the extension disabled itself is checked again
  on every later check. As soon as its target works again (the page is back, the target was
  corrected), the redirect is switched back on and redirects visitors again, without anyone having
  to touch it. Redirects you disabled by hand are never switched back on. See
  [Lifecycle of a redirect](#lifecycle-of-a-redirect).
- **Canonical targets** — a target that resolves to a TYPO3 page is rewritten to the canonical
  `t3://page?uid=…` link, and a chain of several redirects hopping to each other (up to 10 hops) is
  flattened straight to the final page, so it isn't repeatedly re-checked. This only tidies up
  working redirects; it has nothing to do with re-enabling broken ones.
- **Re-checked on every edit** — the moment you change a redirect's target, it's automatically
  re-enabled and health-checked again, so a fix is confirmed working immediately instead of waiting
  for the next scheduled run.
- **Page delete/hide protection** — deleting or hiding a page automatically disables every redirect
  that pointed at it, with a logged reason, instead of leaving them dangling.
- **Broken-redirect filter** — the Redirects module's filter bar gains a "broken redirect" option, so
  you can list exactly the redirects that need attention.
- **Disable log** — a dedicated log view lists every redirect the health check (or the page-guard)
  has disabled, with the reason, source, target and timestamp — so nothing gets disabled silently.
  Entries can be deleted individually or all at once.
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
  so your own code can react to it. An exception in a listener is logged and never breaks the save or the check.
- **No duplicate/confusing notifications** — the generic "Record saved" toast is suppressed on
  `sys_redirect` saves so it doesn't contradict the health-check's own outcome message (e.g.
  "Redirect disabled").

### Lifecycle of a redirect

A redirect is in one of three states:

| State | What it means | Who sets it | How it becomes active again |
|-------|---------------|-------------|-----------------------------|
| **Active** | The target works and visitors are redirected. | — | — |
| **Disabled automatically** | The check found the target broken (page deleted or hidden, target gone, chain too long, duplicate source). The redirect is marked as a *broken redirect*, an entry is written to the disable log and, if enabled, a notification goes out. | The extension | **Automatically** (healing): the next check — bulk run, command or Scheduler — finds the target working and enables it again. An editor who changes the target in the record gets the same immediately: it is enabled and checked on save. |
| **Disabled by hand** | An editor ticked "disabled" in the redirect record. | The editor | Only by an editor. The extension never switches it back on and skips it in bulk runs. |

The disable log is a history: an entry stays when its redirect is healed, and a redirect that breaks
again gets a new entry. When a check changes a redirect's state, the core's redirect index is rebuilt,
so an enabled redirect works on the live site right away and a disabled one stops redirecting.

### Regex redirects

A regex source (`is_regexp`) has no concrete URL, so there is nothing to request: only the **target** is checked,
and only if it is concrete - a page link or a path that must resolve to a visible page in its site and language, or a
target on another host, which is left alone. A redirect whose **target** contains a capture group (`/new/$1`) is not checked at
all - nothing says whether it is right - and counts as healthy; the pattern itself is not tested either.

### A redirect that points to itself

If the target of a redirect is the very URL it redirects, visitors would be sent in a circle. That is a redirect loop,
and the extension **disables** such a redirect like any broken one, with the reason "The redirect points to itself" -
without sending a request first. It counts as the same URL if the path is the same on the same host, ignoring a
trailing slash (`/a` → `/a/`), and also if the target is a page link to the page that lives at that URL. A target with a
query string (`/a?x=1`) is another URL and no loop. Regex redirects are not checked for this.

### A redirect that hides a page

**What it means.** TYPO3 handles redirects *before* it looks for a page. If the source of a redirect is the URL of a
page that exists and is published, the redirect wins: whoever opens that URL is sent on and never sees the page. The page
is still there in the page tree, but it can no longer be reached by its address.

**Example.** The page *Products* has the URL `/products`. Someone creates a redirect with the source `/products` and the
target `/contact`. From then on `/products` leads to the contact page, although the page *Products* is published.

**Why it is only a warning.** Sometimes this is exactly what you want: the page stays in the tree for now, but all
traffic is meant to move on. Often it is a mistake, for example a redirect created for a URL that still has a page. The
extension cannot tell which, so it **never disables** such a redirect. It only tells you:

- When an editor **saves** the redirect, a notice in the backend says which page it hides.
- `extendredirect:checkredirects` lists every such redirect as `Warning: redirect #12 (example.com/products): The redirect
  hides the page "Products" (uid 5) ...` and ends with a note with the number of warnings. The exit code is not
  changed, so a Scheduler task does not fail because of it.

**How to fix it.** If it is a mistake, delete or disable the redirect. If you want the redirect, unpublish or delete the
page, otherwise the warning stays.

**What is looked at.** Only enabled, non-regex redirects whose check is healthy - a redirect that gets disabled hides
nothing. The source must be the URL of a *visible* page of the redirect's site and language: a language prefix such as
`/de/products` selects the German page, and a redirect for all domains (`*`) is reported if the page exists on any site.
Hidden and deleted pages are not reported, nor are pages of another site or language than the one the redirect applies
to.

### Redirects to files

A target that is a file is judged by the file, not by a page lookup or a request: a static file below the public path
(`/fileadmin/docs/report.pdf`, also as an absolute URL of one of your sites) or a file link (`t3://file?uid=5`) is
healthy if the file exists, and broken if it is missing - below the URL prefix of a local file storage such as
`/fileadmin/`, or when the file record is gone or the file is gone from its storage. A target outside the file
storages that is no existing file is treated as before (a page lookup). Nothing is rewritten.

### Several sites and languages

- A redirect with a **specific source host** (a site domain or the domain of a language) is checked on exactly that
  domain. A redirect for **`*`** applies on every domain of every site, language domains included, so it only counts
  as healthy if it works on all of them - a wildcard redirect to a path that exists on one site only is reported
  broken. Use the source host of the site instead.
- The **language** of a target comes from its URL: a prefix such as `/de/`, the domain of a language, or `&_language=1`
  (also `&L=1`) on a page link. A page that is not translated into that language is broken, unless the language's fallback
  chain (`fallbacks` of the site language, any fallback type) reaches the default language or another language that has
  the page. A disabled language has no frontend, so
  redirects into it are broken. Pages for all languages (`-1`) are available in every language.
- The **page delete/hide protection** only disables redirects whose target is the page's URL in its own site and
  language - the same path on another site or in another language is left alone. A wildcard redirect with a relative
  target counts for every site. Hiding a default language page also covers its translations.

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

Mail transport and webhook call run **after the response**: under PHP-FPM the editor's request is finished first
(`fastcgi_finish_request()`), so a slow SMTP server or webhook endpoint never keeps the editor waiting. In the CLI
(command, Scheduler) it is sent at once, and without PHP-FPM the request still waits for it.

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
