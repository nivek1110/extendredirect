..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Requirements: PHP 8.3+, TYPO3 13.4 LTS or 14.3 LTS, system extensions `redirects` and `scheduler`.

Composer
========

..  code-block:: bash

    composer require nivek1110/extendredirect

TER
===

Download the extension from the `TYPO3 Extension Repository
<https://extensions.typo3.org/extension/extendredirect>`__ and install it in the Extension
Manager. Afterwards run :guilabel:`Admin Tools > Maintenance > Analyze Database Structure` so
the new columns and the log table are created.
