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

    `request`: follow the redirect through TYPO3's frontend in the same PHP process, like a visitor would see it.
    `database`: resolve against the `pages`/`sys_redirect` tables without any request.

    ..  note::
        `request` mode runs inside TYPO3, not over the network. The site does not have to be reachable over HTTP from
        the server, and Basic Auth, IP restrictions, a firewall or a CDN in front of it are not involved and need no
        configuration. This holds for the Scheduler and the command too: they run the same code and do not call the
        public URL. It needs a correct site base (Site Configuration > Base), because that is how the extension
        finds the site of a redirect. Redirects or rewrites defined in the web server configuration (outside TYPO3)
        are not seen. `database` mode needs neither.

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

    If the endpoint is behind Basic Auth, put the credentials in the URL (`https://user:password@host/path`): they are
    sent as an `Authorization` header and masked as `***` in error messages. The URL is saved with the extension
    configuration, so a password in it ends up in `config/system/settings.php`. To keep it out of there, set it in
    `config/system/additional.php`, for example from an environment variable:
    :php:`$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['notifyWebhookUrl'] = getenv('REDIRECT_WEBHOOK_URL');`

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
    Mail transport and webhook call run after the response: under PHP-FPM the request of the editor is finished
    first (`fastcgi_finish_request()`), so a slow SMTP server or endpoint never keeps the editor waiting. In the CLI
    (command, Scheduler) they are sent at once; without PHP-FPM the request still waits.

..  note::
    Nothing is sent by `--dry-run`. A failing mail transport is only logged and never breaks a save, a
    check or a page deletion.
