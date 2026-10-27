..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

The Redirects module (:guilabel:`Link Management > Redirects`) gains the broken-redirect
filter, a "last checked" column and filter, the CSV import and export and the disable log.

..  _usage-lifecycle:

Lifecycle of a redirect
=======================

A redirect is in one of three states:

*   **Active**: the target works and visitors are redirected.
*   **Disabled automatically**: the check found the target broken (page deleted or hidden, target gone, chain
    too long, duplicate source). The redirect is marked as a *broken redirect*, an entry is written to the
    disable log and, if enabled, a notification goes out. It is enabled again **automatically** ("healing") by
    the next check that finds the target working - a bulk run, the command or the Scheduler. An editor who
    changes the target in the record gets the same immediately: the redirect is enabled and checked on save.
*   **Disabled by hand**: an editor ticked :guilabel:`Disabled` in the redirect record. The extension never
    switches it back on and skips it in bulk runs; only an editor can enable it again.

The disable log is a history: an entry stays when its redirect is healed, and a redirect that breaks again gets
a new entry. When a check changes the state of a redirect, the core's redirect index is rebuilt, so an enabled
redirect works on the live site right away and a disabled one stops redirecting.

Regex redirects
===============

A regex source (`is_regexp`) has no concrete URL, so there is nothing to request: only the **target** is checked,
and only if it is concrete - a page link or a path that must resolve to a visible page in its site and language, or
a target on another host, which is left alone. A redirect whose **target** contains a capture group (:php:`/new/$1`) is not
checked at all - nothing says whether it is right - and counts as healthy; the pattern itself is not tested either.

Redirects to files
==================

A target that is a file is judged by the file, not by a page lookup or a request: a static file below the public path
(`/fileadmin/docs/report.pdf`, also as an absolute URL of one of your sites) or a file link (`t3://file?uid=5`) is
healthy if the file exists, and broken if it is missing - below the URL prefix of a local file storage such as
`/fileadmin/`, or when the file record is gone or the file is gone from its storage. A target outside the file
storages that is no existing file is treated as before (a page lookup). Nothing is rewritten.

Several sites and languages
===========================

*   A redirect with a **specific source host** (a site domain or the domain of a language) is checked on exactly that
    domain. A redirect for `*` applies on every domain of every site, language domains included, so it only counts as
    healthy if it works on all of them: a wildcard redirect to a path that exists on one site only is reported broken.
    Use the source host of the site instead.
*   The **language** of a target comes from its URL: a prefix such as `/de/`, the domain of a language, or
    `&_language=1` (also `&L=1`) on a page link. A page that is not translated into that language is broken, unless the
    language's fallback chain (`fallbacks` of the site language, any fallback type) reaches the default language or
    another language that has the page. A disabled language has no
    frontend, so redirects into it are broken. Pages for all languages (`-1`) are available in every language.
*   The **page delete/hide protection** only disables redirects whose target is the page's URL in its own site and
    language; the same path on another site or in another language is left alone. A wildcard redirect with a relative
    target counts for every site. Hiding a default language page also covers its translations.

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
