# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.2.0] - 2026-10-26

### Added
- Redirects module: a "Last checked" column and a "Last check" filter (never checked, not checked for 1, 7 or 30
  days), so you can see whether the Scheduler actually runs.
- Redirects module: an "Export redirects" button downloads all redirects as CSV in the import format, so an export
  can be edited and imported again. The CSV import accepts the optional columns `is_regexp` and `disabled` too.
- PSR-14 event `Nivek1110\Extendredirect\Event\RedirectDisabledEvent`, dispatched for every redirect the health check
  or the page delete/hide guard disables, so your own code can react to it.
- Optional webhook for disabled redirects: the extension settings `notifyWebhookUrl` and `notifyWebhookFormat`
  (`text` for Slack/Mattermost/Rocket.Chat, or structured `json`) post the same notification as the mail, with
  the same digest/single rhythm. It is switched by `notifyOnDisable`; failures are logged and never fatal.

### Fixed
- Page delete/hide protection: a path target now only counts as pointing at the page if it is the page's URL - the
  slug behind the base path of the page's language, on a host of that site or language. Before, any target that merely
  ended with the slug was disabled: the same path on another site, a default language redirect when only its
  translation was hidden, and (for the root page `/`) nearly every redirect with a trailing slash. A page link with
  parameters (`t3://page?uid=5&_language=1`) is recognised too, pages for all languages (`-1`) match in every
  language, and hiding a default language page also covers redirects to its translations. Redirects to the root page
  are found by page link and by their exact URL (`/`, `https://example.com/`, `/de/`).
- Languages in both check modes: a page link into a disabled language is broken, a missing translation is broken
  unless the language falls back (`fallbackType: fallback`) to the default language or to another language that has the
  page, and a page for all languages (`-1`) is available in every language.
- `database` check mode, languages: a target with a language prefix (`/de/products`) or a source host that is a language
  domain is now resolved in that language, to the translated page; `&_language=` / `&L=` on a page link is checked for
  an available translation. Before, the language prefix made such targets look broken.
- Language domains: a language with a domain of its own (for example `de.example.com`) is now a known host. Before,
  only site bases were: a redirect with such a source host was disabled as "unknown source host", and the CSV import
  rejected it. The check requests the redirect on that domain, a `*` redirect is checked on every domain including the
  language domains, and in `database` mode the site that owns the domain is used.
- `request` check mode, multi-site: a disabled redirect with a page link as target is now requested on the site (and in
  the language) of that page, built by the site router, instead of on every site of a wildcard source host. An absolute
  URL of a known site is requested there only.
- `request` check mode: a disabled redirect whose page link carries `&_language=1` (or the legacy `&L=1`) is requested
  in that language, and counts as broken if the page has no such translation. (A plain `&language=1` is not a
  language parameter in TYPO3; it only becomes a query string.)
- `request` check mode: an exception from the frontend (for example a site without TypoScript, "No page configured
  for type=0") no longer aborts the whole bulk run; it counts as a server error of that one redirect.
- `request` check mode: a disabled (auto-disabled) redirect could never heal. The core ignores disabled redirects, so
  its source URL always answered 404 and the check stayed "broken" even after the target was fixed. Disabled
  redirects are now checked via their target (page link, same-site path); a target on another host is left alone, as
  in `database` mode.
- Re-enabling or disabling a redirect through a check (bulk run) now rebuilds the core's redirect
  index. Before, the state was only written to the database: a healed redirect did not redirect on the live site
  until the cache was flushed, and the next check, which requests the source URL, disabled it again.

### Changed
- `DisableNotificationService` gets the PSR-14 event dispatcher as a fourth constructor argument (only relevant
  if you instantiate it yourself).

### Upgrade notes
- The new extension settings `notifyWebhookUrl` and `notifyWebhookFormat` are optional; nothing
  changes until you set a webhook URL. Flush the caches (the new event class is excluded from dependency injection).

## [1.1.2] - 2026-10-26

### Changed
- New extension icon: a shield with a redirect arrow that turns from a dashed (broken) into a solid path, plus a
  green health-check mark, replaces the generic grey placeholder. It is used in the Extension Manager and as the
  icon of the disable log table.

### Upgrade notes
- No functional, database or configuration changes.

## [1.1.1] - 2026-10-26

### Changed
- `extendredirect:checkredirects` reports a redirect that an earlier check already disabled and that is still
  broken as "Still broken" instead of "Disabled", and the summary states how many redirects were newly disabled
  (or would be, with `--dry-run`).

### Upgrade notes
- No database changes and no new settings; only the output of the command changes.

## [1.1.0] - 2026-10-26

### Added
- Optional email notification when a redirect is disabled (health check and page delete/hide protection):
  `notifyOnDisable` (off by default), `notifyRecipients`, `notifyMode` (`digest` = one summary mail per run,
  `single` = one mail per redirect) and `notifyLanguage` (`en`/`de`). No mail on `--dry-run`; a failing mail
  transport is logged and never breaks the save or check.

### Upgrade notes
- No database changes. The new options are in the extension configuration (category *notifications*);
  the notification stays off until `notifyOnDisable` is enabled.

## [1.0.0] - 2026-10-23

First release. Makes TYPO3's Redirects module self-maintaining.

### Features
- **Health check** of every redirect target, either by following it through a frontend request (`request` mode,
  default) or purely against the `pages`/`sys_redirect` tables (`database` mode). Broken redirects are disabled.
- **Self-healing targets**: page targets are rewritten to `t3://page?uid=…`, redirect chains (up to 10 hops) are
  flattened to the final page.
- **Re-check on every edit**: changing a redirect's target re-enables and re-checks it.
- **Page delete/hide protection**: redirects pointing to a deleted or hidden page are disabled with a logged reason.
- **Broken-redirect filter** in the Redirects module.
- **Disable log** with reason, source, target and timestamp.
- **CSV import** with validation (unknown source hosts, duplicate source paths per host).
- **Commands**, both usable from the Scheduler:
  - `extendredirect:checkredirects` with `--dry-run`, `--delay=<ms>`, `--max-runtime=<s>` and `--limit=<n>`.
    Never-checked and least recently checked redirects come first; redirects you disabled by hand are never
    re-enabled.
  - `extendredirect:cleanuplog --days=<n>` deletes disable log entries older than n days (default 30).

### Requirements
- PHP 8.3 or newer
- TYPO3 13.4 LTS or 14.3 LTS

### Installation
Install the extension, then run *Admin Tools → Maintenance → Analyze Database Structure* to create the new
columns and the log table. See the README for configuration (`checkMode`) and usage.
