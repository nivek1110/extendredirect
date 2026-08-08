<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Controller;

use Nivek1110\Extendredirect\Controller\ManagementController;
use PHPUnit\Framework\MockObject\Generator\ClassIsFinalException;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Information\Typo3Version;

class ManagementControllerTest extends TestCase
{
    /**
     * The core ManagementController constructor's parameter list differs between
     * TYPO3 13 and 14 (see the class-level note on ManagementController) - build
     * arguments purely via reflection so this test compiles against whichever
     * core is actually installed instead of hardcoding either signature.
     */
    private function createController(): ManagementController
    {
        $constructor = (new \ReflectionClass(ManagementController::class))->getConstructor();
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $arguments[] = $this->createArgument($type instanceof \ReflectionNamedType ? $type->getName() : \stdClass::class);
        }

        return new ManagementController(...$arguments);
    }

    private function createArgument(string $className): object
    {
        try {
            return $this->createStub($className);
        } catch (ClassIsFinalException) {
            return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
        }
    }

    public function testRedirectsModuleRouteIdentifierMatchesInstalledCoreMajor(): void
    {
        $controller = $this->createController();

        $method = new \ReflectionMethod($controller, 'redirectsModuleRouteIdentifier');
        $method->setAccessible(true);
        $result = $method->invoke($controller);

        $expected = (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects';
        self::assertSame($expected, $result);
    }
}
