<?php

namespace TWT\TwtRedirect\EventListener;

use TWT\TwtRedirect\Repository\RedirectDemand;
use TWT\TwtRedirect\Repository\RedirectRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
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
    }
}
