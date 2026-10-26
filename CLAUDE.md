# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

TYPO3 extension **`nivek1110/extendredirect`** (TER key `extendredirect`, PHP namespace
`Nivek1110\Extendredirect\`). It makes TYPO3's core Redirects module self-maintaining: health check of
redirect targets, auto-disabling of broken redirects, page delete/hide guard, CSV import, a disable log
and two schedulable CLI commands. User-facing docs: `README.md` and `Documentation/`; release notes:
`CHANGELOG.md` (keep it updated with every user-visible change).

## How to work in this repo

Work **TDD**: write-failing-test → run (verify FAIL) → minimal implementation → run (verify PASS) →
commit. Don't batch unrelated changes or skip the failing-test step; commit at logical boundaries.

## Commands

Dependencies install into `.Build/` (a Composer `vendor-dir`, not the default `vendor/`):

```bash
composer update                                          # installs into .Build/
.Build/bin/phpunit -c Build/phpunit/UnitTests.xml        # all unit tests (pure PHP, fast)
.Build/bin/phpunit -c Build/phpunit/FunctionalTests.xml  # functional tests (boot TYPO3 + DB)
```

Dev tooling pinned in `require-dev`: `phpstan/phpstan ^2.1`, `friendsofphp/php-cs-fixer ^3.51`.

### DDEV testbed

The committed `.ddev/` config (nginx-fpm, PHP 8.3, MariaDB) provides a clickable sandbox. Custom
commands wrap the workflows — after a fresh clone, `ddev start` then:

```bash
ddev setup              # composer install into .Build/
ddev demo               # (re)build the default demo at https://extendredirect.ddev.site/ (.Build/, current version)
ddev install-v13        # clickable TYPO3 v13 demo at https://v13.extendredirect.ddev.site/  (--fresh to rebuild)
ddev install-v14        # clickable TYPO3 v14 demo at https://v14.extendredirect.ddev.site/
ddev install-all        # both side-by-side
ddev test [unit|functional|all]   # PHPUnit suites
ddev lint               # PHPStan + php-cs-fixer (dry-run); `ddev cgl-fix` auto-fixes
ddev scan-extension [13|14|build|all]   # TYPO3 Extension Scanner (Install Tool "Scan Extension Files") on CLI
ddev worktree-init [name]         # give a git worktree its own DDEV project name
```

Backend login on every instance: user `admin`, random password printed at the end of the setup run (none is stored in the repo). The `v13`/`v14` instances live in Docker
volumes (`/var/www/html/v13|v14`, served by `.ddev/nginx_full/v13.conf|v14.conf`), each a full TYPO3
base-distribution with EXT:extendredirect wired via a Composer path repository to the working tree —
edits to `Classes/` reflect live. The shared seeder is `Build/Scripts/setup-demo-instance.sh`
(env/path-parameterised); `setup-version-instance.sh` drives the per-version scaffold.

## Test layering (important for speed)

Tests split into two suites by what they need to boot:
- **`Tests/Unit/`** — pure PHPUnit `TestCase`, no TYPO3 bootstrap. Keep framework-free logic here so
  it runs fast.
- **Functional tests must restore request-related globals.** The testing framework does not reset
  `$GLOBALS['TYPO3_REQUEST']` / `BE_USER`; a leaked request without `applicationType` broke later test classes on
  TYPO3 13.4 only (its DataHandler reads the global) while 14.3 stayed green. Save them in `setUp()`, restore in
  `tearDown()`. Before tagging, run the suites against BOTH majors (`composer update --with="typo3/cms-core:^13.4"`,
  run it twice if vendor ends up half-updated, then update back).
- **`Tests/Functional/`** — `typo3/testing-framework`, boots TYPO3 + DB. Used for anything touching
  FAL, middlewares, TCA, or ViewHelpers.

## Architecture

Entry points -> services -> repositories:

- `Hooks/DataHandlerHook` (registered in `ext_localconf.php`, legacy DataHandler hooks - no PSR-14 equivalent for
  page delete) only routes events: `sys_redirect` save -> `HealthCheckService::runHealthCheckForSavedRecord()`;
  page delete/hide -> `PageRedirectGuardService`.
- `Service/HealthCheckService` - core logic. Two modes via ext conf `checkMode`: `request` follows the source URL
  through an in-process frontend sub-request (`redirectFollower()`), `database` resolves purely against
  `pages`/`sys_redirect` (follows chains up to `MAX_CHAIN_HOPS`, flattens them, rewrites targets to
  `t3://page?uid=`). `runBulkCheck()` is the bulk entry (dry-run, delay, max-runtime, limit).
- `Service/DisableNotificationService` (a singleton) queues each disabled redirect from `logDisable()` /
  `PageRedirectGuardService` and mails them on `flush()` (end of a bulk run / page guard / single check; immediately
  in `single` mode). Off unless `notifyOnDisable`; mail failures are logged, never thrown. Template:
  `Resources/Private/Templates/Email/RedirectsDisabled.{html,txt}` (plain `.html`/`.txt` names work on 13.4 and 14.x);
  the text template wraps translations in `f:format.raw` so labels with quotes are not HTML-escaped.
- `Command/CheckCommand` (`extendredirect:checkredirects`) and `Command/CleanupLogCommand`
  (`extendredirect:cleanuplog`) - both schedulable, registered with description in `Configuration/Services.yaml`.
- `Controller/ManagementController` + `Resources/Private/Templates/Management/` extend the core Redirects module
  (overview, log, CSV import); `EventListener/AddFilterEventListener` adds the broken-redirect filter;
  `Middleware/SuppressRecordSavedNotificationMiddleware` hides the generic "Record saved" toast.
- `Repository/RedirectRepository`, `DisableLogRepository`, `RedirectDemand` - all DB access (QueryBuilder).

Locked decisions:
- Redirects disabled by hand (`disabled=1`, `broken_redirect=0`) are never re-enabled by a bulk run; only our own
  (`broken_redirect=1`) are, once healthy.
- `sys_redirect.redirect_count` is a `varchar` on purpose (stores `'-'` on errors).
- Bulk runs order by `sys_redirect.last_checked` (never-checked first) so `--limit`/`--max-runtime` rotate.
- `HealthCheckService::redirectFollower()` must restore everything the frontend sub-request clobbers (`BE_USER`,
  `TYPO3_REQUEST`, Context aspects `backend.user` etc.): otherwise the backend middleware deletes the editor's session
  cookie after a save (logged out). Keep the actual dispatch in `dispatchSubRequest()`; the state handling is tested.
- No real per-request timeout/parallelism: checks are in-process sub-requests sharing global state.
- Version lives in `ext_emconf.php` AND `composer.json` (`extra.typo3/cms.version`, needed to avoid the TYPO3 14.3
  `ext_emconf.php` deprecation) - keep both in sync on release; `ext_emconf.php` stays for TER.
- No `ext_tables.sql`: since TYPO3 13 the core derives DB columns from TCA (`DefaultTcaSchema`), so every column of
  `sys_redirect` (ours) and the log table is declared in TCA with an auto-creating type (`check`, `input`, `number`,
  `text`, `datetime` - NOT `passthrough`). Trade-off: no custom indexes on the log table.
- Releases: tag `vX.Y.Z` on a green `main` commit; `.github/workflows/release.yml` creates the GitHub release from the
  CHANGELOG section (`Build/Scripts/release-notes.sh`) after checking the tag against `composer.json`/`ext_emconf.php`.
  `ReleaseNotesTest` fails if the CHANGELOG lacks a section for the current version or the version files disagree.
  The same workflow has a `ter` job (Tailor `ter:publish`, comment = `release-notes.sh --plain`) that is a no-op until the
  repo secret `TYPO3_API_TOKEN` exists and skips versions already in TER.
- Extension Scanner: `Build/Scripts/extension-scan.php` (also run by `ExtensionScannerTest` in CI) must report no *strong*
  matches. Silence false positives (a method name that only looks like a core one) with a `// @extensionScannerIgnoreLine`
  comment above the line and say why. The demo instances link the whole repo as the extension, so the Install Tool scan
  there lists ~14000 files - use `ddev scan-extension` or a Packagist install for a meaningful result.
- Disable log table `tx_extendredirect_disable_log` has a hidden, admin-only TCA; retention via `cleanuplog`.

Supported floor is **PHP 8.3 / TYPO3 13.4**, test matrix PHP 8.3/8.4/8.5 and TYPO3 13.4 + 14.x. Keep
code dual-version-clean (no APIs exclusive to one major without a guard/adapter).
