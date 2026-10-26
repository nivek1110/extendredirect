<?php

return [
    'record_import' => [
        'path' => '/record_import',
        'access' => 'public',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::handleImportRequest',
    ],
    'redirect_import' => [
        'path' => '/redirect_import',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::importAction',
    ],
    'redirect_export' => [
        'path' => '/redirect_export',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::exportAction',
    ],
    'redirect_disable_log' => [
        'path' => '/redirect_disable_log',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::handleLogRequest',
    ],
    'redirect_recheck' => [
        'path' => '/redirect_recheck',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::recheckAction',
    ],
    'redirect_apply_suggestion' => [
        'path' => '/redirect_apply_suggestion',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::applySuggestionAction',
    ],
    'redirect_disable_log_delete' => [
        'path' => '/redirect_disable_log_delete',
        'target' => \Nivek1110\Extendredirect\Controller\ManagementController::class . '::deleteLogAction',
    ],
];
