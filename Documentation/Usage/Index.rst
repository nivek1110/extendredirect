..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

The Redirects module (:guilabel:`Link Management > Redirects`) gains the broken-redirect
filter, a "last checked" column and filter, the CSV import and export and the disable log.

CSV export
==========

:guilabel:`Export redirects` in the module's toolbar downloads all redirects as a semicolon separated
CSV file in the format of the import (including the optional columns `respect_query_parameters`,
`keep_query_parameters`, `force_https`, `is_regexp` and `disabled`). Cells that would be read as a
formula by a spreadsheet are prefixed with an apostrophe.

Commands
========

Both commands can be run on the CLI or as Scheduler tasks (:guilabel:`Execute console commands`).

..  _usage-checkredirects:

extendredirect:checkredirects
-----------------------------

Checks all redirects and disables broken ones.

..  code-block:: bash

    vendor/bin/typo3 extendredirect:checkredirects --dry-run --limit=200 --max-runtime=120 --delay=100

`--dry-run`
    Only report which redirects would be disabled; nothing is written.
`--delay=<ms>`
    Pause after each checked redirect (rate limit for `request` mode). Default `0`.
`--max-runtime=<seconds>`
    Stop starting new checks after this budget; remaining redirects are reported. Default `0`
    (no limit).
`--limit=<n>`
    Check at most n redirects per run. Default `0` (all).

The output lists every broken redirect: "Disabled redirect" (or "Would disable" with `--dry-run`) for the ones
disabled in this run and "Still broken" for the ones an earlier check had already disabled.

Redirects are processed never-checked first, then least recently checked, so limited runs rotate
through all redirects. Redirects disabled by hand are skipped and never re-enabled.

..  note::
    Checks are in-process sub-requests: there is no per-request timeout and no parallelism.

..  _usage-cleanuplog:

extendredirect:cleanuplog
-------------------------

Deletes disable log entries older than a number of days, comparable to the core
:guilabel:`Table garbage collection` task.

..  code-block:: bash

    vendor/bin/typo3 extendredirect:cleanuplog --days=30

`--days=<n>`
    Delete entries older than n days (integer >= 1). Default `30`.
