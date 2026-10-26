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

..  _configuration-notification:

Email notification
==================

When a redirect is disabled - by the health check (on save or via the command) or by the page delete/hide
protection - a mail can be sent. It is off by default and uses TYPO3's mail configuration
(:php:`$GLOBALS['TYPO3_CONF_VARS']['MAIL']`); the sender is the default mail sender.

..  confval:: notifyOnDisable
    :name: confval-notifyondisable
    :type: boolean
    :Default: 0

    Notify when a redirect is disabled. Switches the mail and the webhook (see below).

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

Webhook
=======

Instead of or in addition to the mail, the same notification can be posted as JSON to a webhook. It
needs `notifyOnDisable` and follows `notifyMode` (one call per run or per redirect).

..  confval:: notifyWebhookUrl
    :name: confval-notifywebhookurl
    :type: string

    http(s) URL that receives the JSON `POST`. Empty = no webhook. Calls time out after 5 seconds.
    Only administrators can set it, and it is not validated beyond the scheme: do not point it at
    internal services you do not control.

..  confval:: notifyWebhookFormat
    :name: confval-notifywebhookformat
    :type: options
    :Default: text

    `text`: `{"text": "..."}`, understood by Slack, Mattermost and Rocket.Chat incoming webhooks.
    `json`: a structured payload with `event` (`redirects_disabled`), `site`, `count` and a list
    `redirects` of `redirect_uid`, `source_host`, `source_path`, `target`, `status_code`, `reason`.

PSR-14 event
============

Independent of all notification settings, the :php:`Nivek1110\Extendredirect\Event\RedirectDisabledEvent`
is dispatched for every redirect that was disabled and logged (by the health check or the page
delete/hide protection). The redirect is already disabled when listeners run.

..  code-block:: php

    use Nivek1110\Extendredirect\Event\RedirectDisabledEvent;
    use TYPO3\CMS\Core\Attribute\AsEventListener;

    #[AsEventListener]
    final class MyListener
    {
        public function __invoke(RedirectDisabledEvent $event): void
        {
            // getRedirectUid(), getSourceHost(), getSourcePath(), getTarget(),
            // getStatusCode(), getReason(), getEntry()
        }
    }

..  note::
    Nothing is sent by `--dry-run`. A failing mail transport is only logged and never breaks a save, a
    check or a page deletion.
