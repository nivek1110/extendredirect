<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Controller;

use Nivek1110\Extendredirect\Controller\ManagementController;
use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use Nivek1110\Extendredirect\Repository\RedirectRepository;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Information\Typo3Version;
use PHPUnit\Framework\MockObject\Generator\ClassIsFinalException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class ManagementControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private DisableLogRepository $logRepository;

    /** @var array<string, mixed> request-related globals as they were before the test, restored in tearDown() */
    private array $originalGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The testing framework does not reset these, and e.g. TYPO3 13.4's DataHandler reads
        // $GLOBALS['TYPO3_REQUEST'] - a request left behind here would break later test classes.
        foreach (['TYPO3_REQUEST', 'BE_USER'] as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $this->originalGlobals[$key] = $GLOBALS[$key];
            }
        }
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
    protected function tearDown(): void
    {
        foreach (['TYPO3_REQUEST', 'BE_USER'] as $key) {
            if (array_key_exists($key, $this->originalGlobals)) {
                $GLOBALS[$key] = $this->originalGlobals[$key];
            } else {
                unset($GLOBALS[$key]);
            }
        }
        parent::tearDown();
    }

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

    /**
     * A request shaped like a real backend module request, so the real controller
     * can build its ModuleTemplate and render the extension's own Fluid templates.
     *
     * @param array<string, mixed> $queryParams
     * @param array<string, UploadedFile> $uploadedFiles
     */
    private function createBackendRequest(array $queryParams = [], array $uploadedFiles = []): ServerRequest
    {
        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['pid' => 0, 'username' => 'admin', 'password' => '', 'admin' => 1]);
        $this->setUpBackendUser((int) $users->lastInsertId());

        $request = (new ServerRequest('https://example.com/typo3/module/redirects', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            // 14.x reads the redirect type from the module data; 13.4 ignores the attribute
            ->withAttribute('moduleData', new ModuleData('redirects', ['redirectType' => 'default']))
            ->withAttribute('route', new Route('/module/redirects', ['packageName' => 'nivek1110/extendredirect']))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule(
                (new Typo3Version())->getMajorVersion() >= 14 ? 'redirects' : 'site_redirects',
                $GLOBALS['BE_USER']
            ))
            ->withQueryParams($queryParams)
            ->withUploadedFiles($uploadedFiles);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        // Core's doc header listeners read the request from the global, as in a real backend request.
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    public function testImportActionImportsUploadedCsvFile(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect']['checkMode'] = 'database';
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        GeneralUtility::makeInstance(SiteWriter::class)
            ->createNewBasicSite('main', (int) $connection->lastInsertId(), 'https://example.com/');

        $csvPath = $this->instancePath . '/typo3temp/var/tests/controller-import.csv';
        @mkdir(dirname($csvPath), 0o777, true);
        file_put_contents($csvPath, "source_host;source_path;target;target_statuscode\nexample.com;/old;/somewhere-external;301\n");

        $request = $this->createBackendRequest([], [
            'redirect' => new UploadedFile($csvPath, (int) filesize($csvPath), UPLOAD_ERR_OK, 'redirect.csv'),
        ]);

        $response = $this->get(ManagementController::class)->importAction($request);

        self::assertSame(200, $response->getStatusCode());
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findAll();
        self::assertCount(1, $redirects);
        self::assertSame('/old', $redirects[0]['source_path']);
        @unlink($csvPath);
    }

    public function testImportFormRendersWithoutUpload(): void
    {
        $response = $this->get(ManagementController::class)->handleImportRequest($this->createBackendRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], GeneralUtility::makeInstance(RedirectRepository::class)->findAll());
    }

    public function testLogRequestPaginatesNewestFirst(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->logRepository->add(array_merge($this->logEntry($i), ['crdate' => $i, 'source_path' => '/entry-' . $i . '-end']));
        }
        $controller = $this->get(ManagementController::class);

        $firstPage = (string) $controller->handleLogRequest($this->createBackendRequest())->getBody();
        $secondPage = (string) $controller->handleLogRequest($this->createBackendRequest(['page' => '2']))->getBody();
        $beyondLast = (string) $controller->handleLogRequest($this->createBackendRequest(['page' => '99']))->getBody();

        self::assertStringContainsString('/entry-25-end', $firstPage);
        self::assertStringNotContainsString('/entry-5-end', $firstPage);
        self::assertStringContainsString('/entry-5-end', $secondPage);
        self::assertStringNotContainsString('/entry-25-end', $secondPage);
        // An out-of-range page falls back to the last one.
        self::assertStringContainsString('/entry-5-end', $beyondLast);
    }

    private function insertRedirect(string $sourcePath, int $lastChecked): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'pid' => 0,
            'source_host' => '*',
            'source_path' => $sourcePath,
            'target' => '/target',
            'target_statuscode' => 301,
            'last_checked' => $lastChecked,
        ]);
    }

    public function testOverviewShowsTheLastCheckedColumnAndFilter(): void
    {
        $this->insertRedirect('/never-checked-path', 0);
        $this->insertRedirect('/checked-path', 1700049600);
        $controller = $this->get(ManagementController::class);

        $body = (string) $controller->handleRequest($this->createBackendRequest())->getBody();

        self::assertStringContainsString('Last checked', $body);
        self::assertStringContainsString('name="demand[last_checked]"', $body);
        self::assertStringContainsString('/never-checked-path', $body);
        self::assertStringContainsString('/checked-path', $body);
        // never: shown as text; checked: formatted date of the timestamp 1700049600 (15.11.2023)
        self::assertStringContainsString('15.11.2023', $body);
    }

    public function testOverviewFilterNarrowsTheListToNeverCheckedRedirects(): void
    {
        $this->insertRedirect('/never-checked-path', 0);
        $this->insertRedirect('/checked-path', 1700049600);
        $controller = $this->get(ManagementController::class);

        $body = (string) $controller->handleRequest(
            $this->createBackendRequest(['demand' => ['last_checked' => 'never']])
        )->getBody();

        self::assertStringContainsString('/never-checked-path', $body);
        self::assertStringNotContainsString('/checked-path', $body);
    }
}
