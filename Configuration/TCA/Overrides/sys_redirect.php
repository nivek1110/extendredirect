<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

$fields = [
    'broken_redirect' => [
        'config' => [
            'renderType' => 'checkboxToggle',
            'type' => 'check',
            'readOnly' => true,
            'items' => [
                [
                    'label' => '',
                ],
            ],
        ],
        'exclude' => true,
        'label' => 'LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:filter.brokenRedirect.label',
    ],
    // Hop count of the last check, or '-' when it failed - hence a string, not a number.
    // Not part of any showitem; the TCA entry only drives the DB column.
    'redirect_count' => [
        'config' => [
            'type' => 'input',
            'readOnly' => true,
            'default' => '0',
        ],
    ],
    'last_checked' => [
        'config' => [
            'type' => 'datetime',
            'readOnly' => true,
            'default' => 0,
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('sys_redirect', $fields);
ExtensionManagementUtility::addToAllTCAtypes('sys_redirect', 'broken_redirect', '', 'after:disabled');

// set default target_statuscode to 301
$GLOBALS['TCA']['sys_redirect']['columns']['target_statuscode']['config']['default'] = 301;
