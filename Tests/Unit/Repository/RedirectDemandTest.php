<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Repository;

use Nivek1110\Extendredirect\Repository\RedirectDemand;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

class RedirectDemandTest extends TestCase
{
    public function testCreateFromRequestWithoutDemandUsesDefaults(): void
    {
        $request = (new ServerRequest())->withQueryParams(['page' => '2']);

        $demand = RedirectDemand::createFromRequest($request);

        self::assertSame(2, $demand->getPage());
        self::assertSame(RedirectDemand::BROKEN_REDIRECT_ALL, $demand->getBrokenRedirect());
        self::assertFalse($demand->hasBrokenRedirect());
    }

    public function testCreateFromRequestReadsDemandFromQueryParams(): void
    {
        $request = (new ServerRequest())->withQueryParams([
            'demand' => [
                'source_host' => 'example.com',
                'source_path' => '/foo',
                'target' => '/bar',
                'target_statuscode' => '301',
                'max_hits' => '5',
                'broken_redirect' => '1',
            ],
        ]);

        $demand = RedirectDemand::createFromRequest($request);

        self::assertSame(['example.com'], $demand->getSourceHosts());
        self::assertSame('/foo', $demand->getSourcePath());
        self::assertSame('/bar', $demand->getTarget());
        self::assertSame([301], $demand->getStatusCodes());
        self::assertSame(5, $demand->getMaxHits());
        self::assertSame(1, $demand->getBrokenRedirect());
        self::assertTrue($demand->hasBrokenRedirect());
    }

    public function testCreateFromRequestReadsDemandFromParsedBody(): void
    {
        $request = (new ServerRequest())->withParsedBody([
            'demand' => ['source_path' => '/from-body'],
        ]);

        $demand = RedirectDemand::createFromRequest($request);

        self::assertSame('/from-body', $demand->getSourcePath());
    }

    public function testSetBrokenRedirectIsReflectedInGetterAndParameters(): void
    {
        $demand = new RedirectDemand();
        self::assertFalse($demand->hasBrokenRedirect());

        $demand->setBrokenRedirect(2);

        self::assertSame(2, $demand->getBrokenRedirect());
        self::assertTrue($demand->hasBrokenRedirect());
        self::assertTrue($demand->hasConstraints());
        self::assertSame(['broken_redirect' => 2], $demand->getParameters());
    }

    public function testGetParametersOmitsUnsetConstraints(): void
    {
        $demand = new RedirectDemand();

        self::assertSame([], $demand->getParameters());
    }

    public function testGetParametersIncludesEverySetConstraint(): void
    {
        $demand = new RedirectDemand(
            page: 1,
            sourceHosts: ['example.com'],
            sourcePath: '/foo',
            target: '/bar',
            statusCodes: [301],
            maxHits: 5,
            brokenRedirect: 1
        );

        self::assertSame([
            'source_path' => '/foo',
            'source_host' => 'example.com',
            'target' => '/bar',
            'target_statuscode' => 301,
            'max_hits' => 5,
            'broken_redirect' => 1,
        ], $demand->getParameters());
    }

    /**
     * TYPO3 14's core Demand takes a "redirectType" argument (and defines DEFAULT_REDIRECT_TYPE), 13.4's
     * has neither. Our constructor must not take it itself; it passes it to the parent only where the parent expects it.
     */
    public function testConstructorDoesNotDependOnCoreOnlyRedirectTypeParameter(): void
    {
        $parameterNames = array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionMethod(RedirectDemand::class, '__construct'))->getParameters()
        );

        self::assertNotContains('redirectType', $parameterNames);
        self::assertContains('brokenRedirect', $parameterNames);
    }

    public function testLastCheckedFilterIsReadFromTheRequestAndExposedAsParameter(): void
    {
        $request = (new ServerRequest())->withQueryParams(['demand' => ['last_checked' => 'stale7']]);

        $demand = RedirectDemand::createFromRequest($request);

        self::assertSame('stale7', $demand->getLastChecked());
        self::assertTrue($demand->hasLastChecked());
        self::assertTrue($demand->hasConstraints());
        self::assertSame(['last_checked' => 'stale7'], $demand->getParameters());
    }

    public function testUnknownLastCheckedValuesAreIgnored(): void
    {
        $request = (new ServerRequest())->withQueryParams(['demand' => ['last_checked' => "stale7'; DROP TABLE x"]]);

        $demand = RedirectDemand::createFromRequest($request);

        self::assertSame('', $demand->getLastChecked());
        self::assertFalse($demand->hasLastChecked());
        self::assertSame([], $demand->getParameters());
    }

    public function testNoLastCheckedFilterByDefault(): void
    {
        $demand = new RedirectDemand();

        self::assertSame('', $demand->getLastChecked());
        self::assertFalse($demand->hasConstraints());
    }
}
