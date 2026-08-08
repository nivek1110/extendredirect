# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

This is a TYPO3 extension, **`nivek1110/extendredirect`** (TER key `extendredirect`, PHP namespace
`Nivek1110\Extendredirect\`). Fresh scaffold, ported over from an earlier extension's DDEV testbed —
`Classes/`, `Configuration/`, `Resources/`, `Tests/` are currently empty. TODO: fill in what this
extension does and update this section (and README.md) once scope is decided.

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
- **`Tests/Functional/`** — `typo3/testing-framework`, boots TYPO3 + DB. Used for anything touching
  FAL, middlewares, TCA, or ViewHelpers.

## Architecture

TODO: document data flow, key abstractions, and any locked design decisions once the extension's
scope is defined.

Supported floor is **PHP 8.3 / TYPO3 13.4**, test matrix PHP 8.3/8.4/8.5 and TYPO3 13.4 + 14.x. Keep
code dual-version-clean (no APIs exclusive to one major without a guard/adapter).
