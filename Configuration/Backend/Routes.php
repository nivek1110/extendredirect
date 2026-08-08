<?php

return [
    'record_import' => [
        'path' => '/record_import',
        'access' => 'public',
        'target' => \TWT\TwtRedirect\Controller\ManagementController::class . '::handleImportRequest',
    ],
    'redirect_import' => [
        'path' => '/redirect_import',
        'target' => \TWT\TwtRedirect\Controller\ManagementController::class . '::importAction',
    ],
    'redirect_disable_log' => [
        'path' => '/redirect_disable_log',
        'target' => \TWT\TwtRedirect\Controller\ManagementController::class . '::handleLogRequest',
    ],
    'redirect_disable_log_delete' => [
        'path' => '/redirect_disable_log_delete',
        'target' => \TWT\TwtRedirect\Controller\ManagementController::class . '::deleteLogAction',
    ],
];
