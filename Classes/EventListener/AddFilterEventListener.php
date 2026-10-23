<?php

namespace Nivek1110\Extendredirect\EventListener;

use Nivek1110\Extendredirect\Repository\RedirectDemand;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Event\ModifyRedirectManagementControllerViewDataEvent;

class AddFilterEventListener
{
    public function __invoke(ModifyRedirectManagementControllerViewDataEvent $event): void
    {
        $redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
        $demand = RedirectDemand::createFromRequest($event->getRequest());
        $redirects = $redirectRepository->findRedirectsByRbDemand($demand);
        $event->setRedirects($redirects);
        $event->setDemand($demand);

        // TYPO3 14 renamed the core "Redirects" module identifier from `site_redirects`
        // (parent `site`) to `redirects` (parent `link_management`), keeping `site_redirects`
        // only as a route alias (Feature #107628). Overview.html builds every link/form/
        // returnUrl against this so their CSRF tokens are minted for the route TYPO3
        // actually dispatches to - a token minted against the alias name doesn't validate.
        $moduleRoute = (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects';
        $event->getView()->assign('moduleRoute', $moduleRoute);
    }
}
