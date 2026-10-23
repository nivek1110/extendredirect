<?php

/**
 * Definitions for modules provided by EXT:extendredirect
 *
 * TYPO3 14 renamed the core "Redirects" module identifier from `site_redirects`
 * (parent `site`) to `redirects` (parent `link_management`), keeping `site_redirects`
 * only as a route alias to it (Feature #107628). Registering our override under the
 * old id would get swallowed by that alias and never reach our controller, so the
 * target identifier/parent must match whichever the installed core actually uses.
 */
$typo3MajorVersion = (new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion();

if ($typo3MajorVersion >= 14) {
    return [
        'redirects' => [
            'parent' => 'link_management',
            'access' => 'user',
            'path' => '/module/link-management/redirects',
            'iconIdentifier' => 'module-redirects',
            'labels' => 'redirects.modules.redirects',
            'aliases' => ['site_redirects'],
            'routes' => [
                '_default' => [
                    'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::handleRequest',
                ],
            ],
            'moduleData' => [
                'redirectType' => 'default',
            ],
        ],
    ];
}

return [
    'site_redirects' => [
        'parent' => 'site',
        'position' => ['after' => 'site_configuration'],
        'access' => 'user',
        'path' => '/module/site/redirects',
        'iconIdentifier' => 'module-redirects',
        'labels' => 'LLL:EXT:redirects/Resources/Private/Language/locallang_module_redirect.xlf',
        'routes' => [
            '_default' => [
                'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::handleRequest',
            ],
        ],
    ],
];
