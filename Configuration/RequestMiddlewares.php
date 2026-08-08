<?php

return [
    'backend' => [
        'extendredirect/suppress-record-saved-notification' => [
            'target' => \Nivek1110\Extendredirect\Middleware\SuppressRecordSavedNotificationMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];
