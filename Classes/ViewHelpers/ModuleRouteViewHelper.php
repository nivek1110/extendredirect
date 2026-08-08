<?php

namespace Nivek1110\Extendredirect\ViewHelpers;

use Nivek1110\Extendredirect\Utility\ModuleRouteUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * @see ModuleRouteUtility::getRedirectsModuleRouteIdentifier()
 */
class ModuleRouteViewHelper extends AbstractViewHelper
{
    public function render(): string
    {
        return ModuleRouteUtility::getRedirectsModuleRouteIdentifier();
    }
}
