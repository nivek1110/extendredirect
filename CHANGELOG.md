# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Optional email notification when a redirect is disabled (health check and page delete/hide protection):
  `notifyOnDisable` (off by default), `notifyRecipients`, `notifyMode` (`digest` = one summary mail per run,
  `single` = one mail per redirect) and `notifyLanguage` (`en`/`de`). No mail on `--dry-run`; a failing mail
  transport is logged and never breaks the save or check.

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
