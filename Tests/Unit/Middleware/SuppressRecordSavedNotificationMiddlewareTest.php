<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Middleware;

use Nivek1110\Extendredirect\Middleware\SuppressRecordSavedNotificationMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;

class SuppressRecordSavedNotificationMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    private function createHandlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    private function createLanguageServiceStub(): object
    {
        return new class {
            public function sL(string $key): string
            {
                return match ($key) {
                    'LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:notification.record_saved.title.singular' => 'Record saved',
                    'LLL:EXT:backend/Resources/Private/Language/locallang_alt_doc.xlf:notification.record_saved.title.plural' => 'Records saved',
                    default => $key,
                };
            }
        };
    }

    /**
     * @param array<int, string> $sessionMessages
     * @return object{savedSessionData: array<int, string>, saveCalls: int, readCalls: int}
     */
    private function createBackendUserFake(array $sessionMessages): object
    {
        return new class ($sessionMessages) {
            /** @var array<int, string> */
            public array $savedSessionData = [];
            public int $saveCalls = 0;
            public int $readCalls = 0;

            /**
             * @param array<int, string> $sessionMessages
             */
            public function __construct(private array $sessionMessages) {}

            /**
             * @return array<int, string>
             */
            public function getSessionData(string $key): array
            {
                $this->readCalls++;

                return $this->sessionMessages;
            }

            public function setAndSaveSessionData(string $key, mixed $data): void
            {
                $this->saveCalls++;
                $this->savedSessionData = $data;
            }
        };
    }

    public function testNonSysRedirectRequestLeavesSessionUntouched(): void
    {
        $request = (new ServerRequest())->withParsedBody(['data' => ['pages' => []]]);
        $response = $this->createStub(ResponseInterface::class);

        $backendUser = $this->createBackendUserFake([]);
        $GLOBALS['BE_USER'] = $backendUser;

        $middleware = new SuppressRecordSavedNotificationMiddleware();
        $result = $middleware->process($request, $this->createHandlerReturning($response));

        self::assertSame($response, $result);
        self::assertSame(0, $backendUser->readCalls);
        self::assertSame(0, $backendUser->saveCalls);
    }

    public function testSysRedirectSaveRemovesOnlyTheRecordSavedNotification(): void
    {
        $request = (new ServerRequest())->withParsedBody(['data' => ['sys_redirect' => ['1' => ['target' => '/foo']]]]);
        $response = $this->createStub(ResponseInterface::class);

        $keptMessage = (string) json_encode(['title' => 'Something else']);
        $recordSavedMessage = (string) json_encode(['title' => 'Record saved']);

        $backendUser = $this->createBackendUserFake([$keptMessage, $recordSavedMessage]);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->createLanguageServiceStub();

        $middleware = new SuppressRecordSavedNotificationMiddleware();
        $middleware->process($request, $this->createHandlerReturning($response));

        self::assertSame(1, $backendUser->saveCalls);
        self::assertSame([$keptMessage], $backendUser->savedSessionData);
    }

    public function testSysRedirectSaveWithoutMatchingNotificationDoesNotPersistSession(): void
    {
        $request = (new ServerRequest())->withParsedBody(['data' => ['sys_redirect' => ['1' => ['target' => '/foo']]]]);
        $response = $this->createStub(ResponseInterface::class);

        $keptMessage = (string) json_encode(['title' => 'Something else']);

        $backendUser = $this->createBackendUserFake([$keptMessage]);
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->createLanguageServiceStub();

        $middleware = new SuppressRecordSavedNotificationMiddleware();
        $middleware->process($request, $this->createHandlerReturning($response));

        self::assertSame(0, $backendUser->saveCalls);
    }

    public function testNoBackendUserLeavesSessionUntouched(): void
    {
        $request = (new ServerRequest())->withParsedBody(['data' => ['sys_redirect' => ['1' => []]]]);
        $response = $this->createStub(ResponseInterface::class);
        $GLOBALS['BE_USER'] = null;

        $middleware = new SuppressRecordSavedNotificationMiddleware();
        $result = $middleware->process($request, $this->createHandlerReturning($response));

        self::assertSame($response, $result);
    }
}
