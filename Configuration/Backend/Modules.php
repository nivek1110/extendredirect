<?php

/**
 * Definitions for modules provided by EXT:twt-redirects
 */
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
                'target' => \TWT\TwtRedirect\Controller\ManagementController::class . '::handleRequest',
            ],
        ],
    ],
];
