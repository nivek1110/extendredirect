..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > extendredirect`

..  confval:: checkMode
    :name: confval-checkmode
    :type: options
    :Default: request

    `request`: follow the redirect live through a frontend sub-request, like a browser.
    `database`: resolve against the `pages`/`sys_redirect` tables without any request.

    ..  note::
        `request` mode needs a reachable, correctly configured site base URL
        (Site Configuration > Base). Use `database` mode for local or offline environments.

Email notification
==================

When a redirect is disabled - by the health check (on save or via the command) or by the page delete/hide
protection - a mail can be sent. It is off by default and uses TYPO3's mail configuration
(:php:`$GLOBALS['TYPO3_CONF_VARS']['MAIL']`); the sender is the default mail sender.

..  confval:: notifyOnDisable
    :name: confval-notifyondisable
    :type: boolean
    :Default: 0

    Send an email when a redirect is disabled.

..  confval:: notifyRecipients
    :name: confval-notifyrecipients
    :type: string

    Comma or semicolon separated list of addresses. Invalid addresses are ignored; without a valid
    address nothing is sent.

..  confval:: notifyMode
    :name: confval-notifymode
    :type: options
    :Default: digest

    `digest`: one mail with every redirect disabled during one check, command run or page change.
    `single`: one mail per redirect, sent immediately.

..  confval:: notifyLanguage
    :name: confval-notifylanguage
    :type: options
    :Default: en

    Language of the mail: `en` or `de`.

..  note::
    Nothing is sent by `--dry-run`. A failing mail transport is only logged and never breaks a save, a
    check or a page deletion.
