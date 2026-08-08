<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\EventListener;

use Nivek1110\Extendredirect\EventListener\AddFilterEventListener;
use Nivek1110\Extendredirect\Repository\RedirectDemand;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Redirects\Event\ModifyRedirectManagementControllerViewDataEvent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class AddFilterEventListenerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRedirect(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', array_merge([
            'pid' => 0,
            'source_host' => 'example.com',
            'source_path' => '/foo',
            'target' => '/bar',
            'target_statuscode' => 301,
        ], $overrides));

        return (int) $connection->lastInsertId();
    }

    public function testPopulatesRedirectsAndDemandFromTheRequestAndAssignsModuleRoute(): void
    {
        $matching = $this->insertRedirect(['source_path' => '/match-me']);
        $this->insertRedirect(['source_path' => '/something-else']);

        $request = (new ServerRequest())->withQueryParams([
            'demand' => ['source_path' => 'match-me'],
        ]);

        $view = $this->createMock(ViewInterface::class);
        $expectedModuleRoute = (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects';
        $view->expects(self::once())->method('assign')->with('moduleRoute', $expectedModuleRoute);

        $event = new ModifyRedirectManagementControllerViewDataEvent(
            demand: new RedirectDemand(),
            redirects: [],
            hosts: [],
            statusCodes: [],
            creationTypes: [],
            showHitCounter: false,
            view: $view,
            request: $request,
            integrityStatusCodes: [],
        );

        (new AddFilterEventListener())($event);

        self::assertCount(1, $event->getRedirects());
        self::assertSame($matching, (int) $event->getRedirects()[0]['uid']);
        self::assertInstanceOf(RedirectDemand::class, $event->getDemand());
    }
}
