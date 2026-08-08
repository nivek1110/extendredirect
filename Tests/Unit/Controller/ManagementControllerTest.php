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
        if ($constructor === null) {
            throw new \RuntimeException('ManagementController is expected to inherit a constructor from its core parent.');
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : \stdClass::class;
            /** @var class-string $name */
            $arguments[] = $this->createArgument($name);
        }

        // Arguments are built dynamically from the constructor's own reflection, so PHPStan
        // can't match them against its fixed parameter types.
        // @phpstan-ignore argument.type
        return new ManagementController(...$arguments);
    }

    /**
     * @param class-string $className
     */
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
