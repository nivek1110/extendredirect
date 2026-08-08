<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Controller;

use Nivek1110\Extendredirect\Controller\ManagementController;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use PHPUnit\Framework\MockObject\Generator\ClassIsFinalException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ManagementControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private DisableLogRepository $logRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
        $this->logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);
    }

    /**
     * The core ManagementController constructor's parameter list differs between
     * TYPO3 13 and 14 (see the class-level note on ManagementController) - build
     * arguments purely via reflection so this test compiles against whichever
     * core is actually installed. deleteLogAction() under test never touches any
     * of these collaborators directly.
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

    /**
     * @return array<string, mixed>
     */
    private function logEntry(int $redirectUid): array
    {
        return [
            'pid' => 0,
            'crdate' => 1000,
            'redirect_uid' => $redirectUid,
            'source_host' => 'example.com',
            'source_path' => '/foo',
            'target' => '/bar',
            'status_code' => '404',
            'reason' => 'not found',
        ];
    }

    public function testDeleteLogActionDeletesOnlyTheGivenLogEntry(): void
    {
        $this->logRepository->add($this->logEntry(1));
        $this->logRepository->add($this->logEntry(2));
        $uidToDelete = (int) $this->logRepository->findAll()[0]['uid'];

        $controller = $this->createController();
        $request = (new ServerRequest())->withQueryParams(['logUid' => (string) $uidToDelete]);

        $response = $controller->deleteLogAction($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->logRepository->countAll());
    }

    public function testDeleteLogActionWithDeleteAllRemovesEveryEntry(): void
    {
        $this->logRepository->add($this->logEntry(1));
        $this->logRepository->add($this->logEntry(2));

        $controller = $this->createController();
        $request = (new ServerRequest())->withQueryParams(['deleteAll' => '1']);

        $response = $controller->deleteLogAction($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, $this->logRepository->countAll());
    }
}
