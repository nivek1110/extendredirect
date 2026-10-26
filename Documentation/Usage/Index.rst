..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

The Redirects module (:guilabel:`Link Management > Redirects`) gains the broken-redirect
filter, a "last checked" column and filter, the CSV import and export and the disable log.

Disable log: check now
======================

Every entry of the disable log has a :guilabel:`Check now` button. It re-checks that redirect: a healthy
one is enabled again, a broken one stays disabled. A redirect that was disabled by hand is never touched.

Once a redirect is healthy and enabled again - through :guilabel:`Check now`, :guilabel:`Apply` or a bulk run -
its entries stay in the log as history and are flagged :guilabel:`Repaired` with the time. They offer no actions
any more; if the redirect breaks again, it gets a new entry.

Suggested target
================

For every broken redirect the disable log proposes up to three existing, visible pages (default language;
standard pages and shortcuts) whose slug resembles the path the redirect pointed at, with a similarity score
in percent. With several sites or languages they stay inside the site(s) of the redirect's source host (every site
for `*`, the host is shown) and inside the language of the target: a language prefix such as `/en/`, or a language
with a domain of its own, selects the translated pages. If the leaf page is gone for good, the nearest existing parent page is offered instead.
:guilabel:`Apply` sets the chosen page (as :php:`t3://page?uid=...`) as the new target and re-checks the
redirect; a healthy redirect is enabled again. Nothing is ever applied automatically, and redirects that were
disabled by hand get no suggestion.

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
