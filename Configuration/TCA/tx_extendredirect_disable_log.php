<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:extendredirect/Resources/Private/Language/locallang.xlf:button.disableLog',
        'label' => 'source_path',
        'label_alt' => 'source_host',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'default_sortby' => 'crdate DESC',
        'rootLevel' => 1,
        'hideTable' => true,
        'adminOnly' => true,
        'iconfile' => 'EXT:extendredirect/Resources/Public/Icons/Extension.svg',
    ],
    'columns' => [
        'redirect_uid' => ['config' => ['type' => 'number', 'readOnly' => true]],
        'source_host' => ['config' => ['type' => 'input', 'readOnly' => true, 'max' => 255]],
        'source_path' => ['config' => ['type' => 'input', 'readOnly' => true, 'max' => 2048]],
        'target' => ['config' => ['type' => 'input', 'readOnly' => true, 'max' => 2048]],
        'status_code' => ['config' => ['type' => 'input', 'readOnly' => true, 'max' => 255]],
        'reason' => ['config' => ['type' => 'text', 'readOnly' => true]],
    ],
    'types' => [
        '0' => ['showitem' => 'source_host, source_path, target, status_code, reason'],
    ],
];
