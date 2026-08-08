<?php


$fields = [
    'broken_redirect' => [
        'config' => [
            'renderType' => 'check',
            'type' => 'check',
            'items' => [
                [
                    0 => '',
                    1 => ''
                ]
            ],
        ],
        'exclude' => true,
        'label' => 'Broken Redirect',
    ],
];

// set default target_statuscode to 301
$GLOBALS['TCA']['sys_redirect']['columns']['target_statuscode']['config']['default'] = 301;
