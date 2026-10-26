<?php

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['Extendredirect']
    = Nivek1110\Extendredirect\Hooks\DataHandlerHook::class;
// -> for datamap hooks
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['Extendredirect']
    = Nivek1110\Extendredirect\Hooks\DataHandlerHook::class;

// Templates of the "redirect disabled" notification mail (FluidEmail looks them up here).
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1788000100] = 'EXT:extendredirect/Resources/Private/Templates/Email';
