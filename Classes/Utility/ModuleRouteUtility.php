<?php

namespace Nivek1110\Extendredirect\Utility;

use TYPO3\CMS\Core\Information\Typo3Version;

class ModuleRouteUtility
{
    /**
     * TYPO3 14 renamed the core "Redirects" module identifier from `site_redirects`
     * (parent `site`) to `redirects` (parent `link_management`), keeping `site_redirects`
     * only as a route alias (Feature #107628). Building URLs/tokens against the old name
     * mints a CSRF token for a route that isn't the one actually invoked, so form/link
     * submissions built with it fail token validation on TYPO3 14.
     */
    public static function getRedirectsModuleRouteIdentifier(): string
    {
        return (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects';
    }
}
