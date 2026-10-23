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
