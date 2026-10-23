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
*   **Self-healing targets** - page targets are rewritten to `t3://page?uid=...`; chains of up
    to 10 redirects are flattened to the final page.
*   **Re-check on edit** - changing a target re-enables and re-checks the redirect.
*   **Page delete/hide protection** - redirects pointing to a deleted or hidden page are disabled
    with a logged reason.
*   **Broken-redirect filter** in the Redirects module.
*   **Disable log** - reason, source, target and timestamp of every automatic disabling.
*   **CSV import** - semicolon-separated, with validation of hosts and duplicate source paths.
*   **Commands** - `extendredirect:checkredirects` and `extendredirect:cleanuplog`, both
    schedulable.
