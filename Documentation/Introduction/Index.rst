..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

Redirects normally sit in the database until someone notices a 404 report. Extend Redirect
catches broken ones automatically - on save, on page delete/hide and in bulk via the Scheduler.

Features
========

*   **Health check** - every redirect target is verified, either by following it through a
    frontend sub-request (`request` mode) or purely against the `pages`/`sys_redirect` tables
    (`database` mode). Redirects whose target no longer resolves are disabled.
*   **Automatic re-enabling ("healing")** - a redirect the extension disabled itself is enabled again by
    the next check that finds its target working; redirects disabled by hand are never touched, see
    :ref:`usage-lifecycle`.
*   **Canonical targets** - page targets are rewritten to `t3://page?uid=...`; chains of up
    to 10 redirects are flattened to the final page. This only tidies up working redirects.
*   **Re-check on edit** - changing a target re-enables and re-checks the redirect.
*   **Page delete/hide protection** - redirects pointing to a deleted or hidden page are disabled
    with a logged reason.
*   **Broken-redirect filter** in the Redirects module.
*   **Last checked** column and filter in the Redirects module.
*   **Disable log** - reason, source, target and timestamp of every automatic disabling.
*   **CSV import and export** - semicolon-separated, with validation of hosts and duplicate source
    paths; an export can be edited and imported again.
*   **Email notification** (optional) - a mail when a redirect is disabled, as one summary mail per
    run or one mail per redirect; off by default, see :ref:`configuration-notification`.
*   **Webhook and PSR-14 event** - post disabled redirects to a chat or your own endpoint, or react to
    :php:`RedirectDisabledEvent` in your own code.
*   **Commands** - `extendredirect:checkredirects` and `extendredirect:cleanuplog`, both
    schedulable.
