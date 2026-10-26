<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Extend Redirect',
    'description' => 'Self-maintaining redirects: health check, automatic disabling of broken redirects, page delete/hide protection, CSV import and disable log with cleanup.',
    'category' => 'be',
    'author' => 'nivek1110',
    'author_email' => '',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '1.2.1',
    'constraints' => [
        'depends' => [
            'php' => '8.3.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
