# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2026-10-23

First release: health check (`request`/`database` mode), self-healing targets, page delete/hide guard,
broken-redirect filter, disable log, CSV import and the CLI/Scheduler commands. For TYPO3 13.4 LTS and 14.3 LTS.

### Added
- `extendredirect:cleanuplog` command (schedulable): deletes disable log entries older than `--days` (default 30).
- `extendredirect:checkredirects` options: `--dry-run`, `--delay=<ms>`, `--max-runtime=<s>`, `--limit=<n>`.
- `sys_redirect.last_checked`: bulk runs process never-checked and least recently checked redirects first.
- Descriptions for both commands in the Scheduler; command labels in `locallang.xlf`/`de.locallang.xlf`.
- TCA for the disable log table; index on its `crdate` column.
- Functional tests for `HealthCheckService` (both check modes), `cleanuplog`, multi-site CSV import and
  `ManagementController` import/log rendering; `Documentation/` and this changelog.

### Fixed
- Saving a redirect in the backend logged the editor out in `request` check mode: the frontend sub-request reset the
  shared `backend.user` context aspect, so the backend middleware deleted the session cookie. The check now restores
  `$GLOBALS['BE_USER']`, `$GLOBALS['TYPO3_REQUEST']` and the context aspects it clobbers.
- A new redirect whose uid could not be resolved after saving made the save hook check every redirect.
- Fatal errors on TYPO3 13.4 (the extension only loaded on 14.x): `ManagementController::registerDocHeaderButtons()`
  and the `RedirectDemand` constructor now match the core signatures of both majors (14.x added `redirectType`).
- Disable log and import page crashed on TYPO3 13.4 (`LinkButton::setAttributes()` only exists in 14.x); the
  `target="_top"` of the close button is now only set where the button supports it.
- DDEV testbed: the per-version demo instances (`ddev install-v13|v14`) lacked `helhum/typo3-console`.
- CSV import skipped a row when the same `source_path` existed on a different host; duplicates are now
  detected per host (a wildcard `*` redirect still counts for every host).
- TYPO3 14.3 deprecation for `ext_emconf.php`: `composer.json` now declares `extra.typo3/cms.version` and
  `Package.providesPackages` (`ext_emconf.php` stays for TER; keep both versions in sync).
- A bulk health check re-enabled redirects that an editor had disabled by hand.
- `broken_redirect` was never registered in the TCA of `sys_redirect`.
- 22 missing German translations.
- Missing extension metadata (`description`, `author`, `state`) in `composer.json` and `ext_emconf.php`.

### Changed
- Removed `ext_tables.sql`: all columns (`sys_redirect.broken_redirect`, `redirect_count`, `last_checked` and the
  disable log table) are derived from TCA by the TYPO3 13+ core. Run "Analyze Database Structure" after updating.
  `redirect_count` now defaults to an empty string instead of `'0'`; the log's `source_path`/`target` become `TEXT`.
- `CheckCommand` no longer sets a conflicting command name; the name comes from `Services.yaml`.
