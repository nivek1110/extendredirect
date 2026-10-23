<?php

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['Extendredirect']
    = Nivek1110\Extendredirect\Hooks\DataHandlerHook::class;
// -> for datamap hooks
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['Extendredirect']
    = Nivek1110\Extendredirect\Hooks\DataHandlerHook::class;
