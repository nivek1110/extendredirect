<?php

namespace Nivek1110\Extendredirect\Tests\Unit\Service;

use Nivek1110\Extendredirect\Service\HealthCheckService;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

class HealthCheckServiceTest extends TestCase
{
    private function createService(SiteFinder $siteFinder): HealthCheckService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);

        return new HealthCheckService($extensionConfiguration, $siteFinder);
    }

    /**
     * @return array<string, Site>
     */
    private function createSites(): array
    {
        return [
            'main' => new Site('main', 1, ['base' => 'https://extendredirect.ddev.site/']),
            'main2' => new Site('main2', 2, ['base' => 'https://extendredirect1.ddev.site/']),
        ];
    }

    /**
     * @return Site[]
     */
    private function invokeFindSitesBySourceHost(HealthCheckService $service, string $sourceHost): array
    {
        $method = new \ReflectionMethod($service, 'findSitesBySourceHost');

        return $method->invoke($service, $sourceHost);
    }

    private function invokeResolveAbsoluteLocation(HealthCheckService $service, string $location): string
    {
        $method = new \ReflectionMethod($service, 'resolveAbsoluteLocation');

        return $method->invoke($service, $location);
    }

    public function testSpecificHostReturnsOnlyMatchingSite(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeFindSitesBySourceHost($service, 'extendredirect1.ddev.site');

        self::assertSame([$sites['main2']], array_values($result));
    }

    public function testNonMatchingHostReturnsNoSiteInsteadOfAnArbitraryFallback(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeFindSitesBySourceHost($service, 'unknown.example.com');

        self::assertSame([], $result);
    }

    public function testWildcardHostReturnsAllSites(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeFindSitesBySourceHost($service, '*');

        self::assertSame(array_values($sites), array_values($result));
    }

    public function testProtocolRelativeLocationIsResolvedUsingTheMatchingSitesScheme(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeResolveAbsoluteLocation($service, '//extendredirect1.ddev.site/home-root-1');

        self::assertSame('https://extendredirect1.ddev.site/home-root-1', $result);
    }

    public function testProtocolRelativeLocationForUnknownHostDefaultsToHttps(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeResolveAbsoluteLocation($service, '//unknown.example.com/foo');

        self::assertSame('https://unknown.example.com/foo', $result);
    }

    public function testAlreadyAbsoluteLocationIsReturnedUnchanged(): void
    {
        $sites = $this->createSites();
        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

        $service = $this->createService($siteFinder);

        $result = $this->invokeResolveAbsoluteLocation($service, 'https://extendredirect.ddev.site/home-root-0');

        self::assertSame('https://extendredirect.ddev.site/home-root-0', $result);
    }
}
