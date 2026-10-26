<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Service;

use Nivek1110\Extendredirect\Event\RedirectDisabledEvent;
use Nivek1110\Extendredirect\Service\DisableNotificationService;
use Nivek1110\Extendredirect\Tests\Support\RecordingMailer;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The PSR-14 event and the optional webhook of DisableNotificationService. Only the decisions
 * (whether, how often, what payload) are tested - the HTTP call itself is the postJson() seam.
 */
class DisableWebhookTest extends TestCase
{
    /** @var \ArrayObject<int, object> */
    private \ArrayObject $events;

    /** @var list<array{url: string, body: string}> */
    private array $posts = [];

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $logged;

    private bool $webhookFails = false;

    private RecordingMailer $mailer;

    /** Collect the "after the response" tasks instead of running them at once. */
    private bool $deferTasks = false;

    /** Use the real runAfterResponse() (which runs at once in the CLI) instead of the test's. */
    private bool $useRealDeferral = false;

    /** @var list<callable> */
    private array $tasks = [];

    /** @var \ArrayObject<string, bool> switches of the fake event dispatcher */
    private \ArrayObject $dispatcherState;

    /**
     * @param array<string, mixed> $configuration
     */
    private function createService(array $configuration): DisableNotificationService
    {
        $this->events = new \ArrayObject();
        $this->posts = [];
        $this->logged = new \ArrayObject();
        $this->webhookFails = false;
        $this->mailer = new RecordingMailer();
        $this->deferTasks = false;
        $this->useRealDeferral = false;
        $this->tasks = [];

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        $this->dispatcherState = new \ArrayObject(['failing' => false]);
        $dispatcher = new class ($this->events, $this->dispatcherState) implements EventDispatcherInterface {
            /**
             * @param \ArrayObject<int, object> $events
             * @param \ArrayObject<string, bool> $state
             */
            public function __construct(private readonly \ArrayObject $events, private readonly \ArrayObject $state) {}

            public function dispatch(object $event): object
            {
                $this->events->append($event);
                if ($this->state['failing']) {
                    throw new \RuntimeException('listener exploded');
                }

                return $event;
            }
        };
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $logged */
            public function __construct(private readonly \ArrayObject $logged) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logged->append($level . ': ' . $message);
            }
        };
        $test = $this;

        return new class ($extensionConfiguration, $this->mailer, $logger, $dispatcher, $test) extends DisableNotificationService {
            public function __construct(
                ExtensionConfiguration $extensionConfiguration,
                RecordingMailer $mailer,
                \Psr\Log\LoggerInterface $logger,
                EventDispatcherInterface $dispatcher,
                private readonly DisableWebhookTest $test
            ) {
                parent::__construct($extensionConfiguration, $mailer, $logger, $dispatcher);
            }

            protected function createEmail(array $entries, string $language): Email
            {
                return (new Email())->subject((string) count($entries))->text('x');
            }

            protected function postJson(string $url, string $body): void
            {
                $this->test->recordPost($url, $body);
            }

            protected function runAfterResponse(callable $task): void
            {
                if ($this->test->usesRealDeferral()) {
                    parent::runAfterResponse($task);

                    return;
                }
                $this->test->defer($task);
            }
        };
    }

    public function usesRealDeferral(): bool
    {
        return $this->useRealDeferral;
    }

    public function defer(callable $task): void
    {
        if ($this->deferTasks) {
            $this->tasks[] = $task;

            return;
        }
        $task();
    }

    public function recordPost(string $url, string $body): void
    {
        $this->posts[] = ['url' => $url, 'body' => $body];
        if ($this->webhookFails) {
            throw new \RuntimeException('connection refused');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(int $uid): array
    {
        return [
            'redirect_uid' => $uid,
            'source_host' => 'example.com',
            'source_path' => '/old-' . $uid,
            'target' => '/gone',
            'status_code' => '404',
            'reason' => 'Page not found',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function webhook(string $format = 'text', string $mode = 'digest'): array
    {
        return [
            'notifyOnDisable' => '1',
            'notifyWebhookUrl' => 'https://chat.example.com/hooks/abc',
            'notifyWebhookFormat' => $format,
            'notifyMode' => $mode,
        ];
    }

    public function testEveryDisabledRedirectIsDispatchedAsEventEvenWhenNotificationsAreOff(): void
    {
        $service = $this->createService([]);

        $service->queue($this->entry(7));

        self::assertCount(1, $this->events);
        $event = $this->events[0];
        self::assertInstanceOf(RedirectDisabledEvent::class, $event);
        self::assertSame(7, $event->getRedirectUid());
        self::assertSame('example.com', $event->getSourceHost());
        self::assertSame('/old-7', $event->getSourcePath());
        self::assertSame('/gone', $event->getTarget());
        self::assertSame('Page not found', $event->getReason());
        self::assertSame('404', $event->getStatusCode());
        self::assertSame([], $this->posts);
    }

    public function testDigestModeSendsOneWebhookCallOnFlush(): void
    {
        $service = $this->createService($this->webhook('text'));

        $service->queue($this->entry(1));
        $service->queue($this->entry(2));
        self::assertSame([], $this->posts, 'nothing before flush()');
        $service->flush();

        self::assertCount(1, $this->posts);
        self::assertSame('https://chat.example.com/hooks/abc', $this->posts[0]['url']);
        $payload = json_decode($this->posts[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['text'], array_keys($payload));
        self::assertStringContainsString('2 redirects were disabled', $payload['text']);
        self::assertStringContainsString('example.com/old-1', $payload['text']);
        self::assertStringContainsString('Page not found', $payload['text']);
    }

    public function testSingleModePostsImmediatelyPerRedirect(): void
    {
        $service = $this->createService($this->webhook('text', 'single'));

        $service->queue($this->entry(1));
        $service->queue($this->entry(2));

        self::assertCount(2, $this->posts);
        $payload = json_decode($this->posts[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('1 redirect was disabled', $payload['text']);
    }

    public function testJsonFormatCarriesTheStructuredEntries(): void
    {
        $service = $this->createService($this->webhook('json'));

        $service->queue($this->entry(5));
        $service->flush();

        $payload = json_decode($this->posts[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('redirects_disabled', $payload['event']);
        self::assertSame(1, $payload['count']);
        self::assertSame(
            ['redirect_uid' => 5, 'source_host' => 'example.com', 'source_path' => '/old-5', 'target' => '/gone', 'status_code' => '404', 'reason' => 'Page not found'],
            $payload['redirects'][0]
        );
    }

    public function testNoWebhookWithoutTheMasterSwitchOrAUrl(): void
    {
        $service = $this->createService(['notifyWebhookUrl' => 'https://chat.example.com/x']);
        $service->queue($this->entry(1));
        $service->flush();

        $service = $this->createService(['notifyOnDisable' => '1', 'notifyWebhookUrl' => '']);
        $service->queue($this->entry(1));
        $service->flush();

        self::assertSame([], $this->posts);
    }

    public function testOnlyHttpAndHttpsUrlsAreCalled(): void
    {
        foreach (['ftp://example.com/x', 'file:///etc/passwd', 'javascript:alert(1)', 'not a url', 'https://'] as $url) {
            $service = $this->createService(array_merge($this->webhook(), ['notifyWebhookUrl' => $url]));
            $service->queue($this->entry(1));
            $service->flush();
            self::assertSame([], $this->posts, $url);
            self::assertNotCount(0, $this->logged, $url);
        }
    }

    public function testAFailingWebhookIsLoggedAndNeverThrown(): void
    {
        $service = $this->createService($this->webhook());
        $this->webhookFails = true;

        $service->queue($this->entry(1));
        $service->flush();

        self::assertCount(1, $this->posts);
        self::assertStringContainsString('error: Calling the redirect disable webhook failed: connection refused', implode("\n", $this->logged->getArrayCopy()));
    }

    public function testTheWebhookWorksWithoutMailRecipients(): void
    {
        $service = $this->createService($this->webhook());

        $service->queue($this->entry(1));
        $service->flush();

        self::assertCount(1, $this->posts);
        self::assertCount(0, $this->logged, 'a missing recipient is no problem when a webhook is configured');
    }

    public function testNothingConfiguredAtAllIsStillWarnedAbout(): void
    {
        $service = $this->createService(['notifyOnDisable' => '1']);

        $service->queue($this->entry(1));
        $service->flush();

        self::assertStringContainsString('warning:', implode("\n", $this->logged->getArrayCopy()));
    }

    public function testAFailingEventListenerIsLoggedAndNeverBreaksTheSaveOrTheNotifications(): void
    {
        $service = $this->createService($this->webhook('text', 'single'));
        $this->dispatcherState['failing'] = true;

        $service->queue($this->entry(1));

        self::assertCount(1, $this->events, 'the event was dispatched');
        self::assertCount(1, $this->posts, 'the webhook still goes out');
        self::assertStringContainsString('error: A listener of RedirectDisabledEvent failed: listener exploded', implode("\n", $this->logged->getArrayCopy()));
    }

    public function testMailAndWebhookGoOutAfterTheResponse(): void
    {
        $service = $this->createService($this->webhook('text', 'single') + ['notifyRecipients' => 'ops@example.com']);
        $this->deferTasks = true;

        $service->queue($this->entry(1));

        self::assertSame([], $this->posts, 'the webhook waits for the end of the request');
        self::assertCount(0, $this->mailer->emails(), 'so does the mail transport');
        self::assertCount(2, $this->tasks);

        foreach ($this->tasks as $task) {
            $task();
        }
        self::assertCount(1, $this->posts);
        self::assertCount(1, $this->mailer->emails());
    }

    public function testAFailureInADeferredTaskIsLoggedAndNeverThrown(): void
    {
        $service = $this->createService($this->webhook('text', 'single'));
        $this->deferTasks = true;
        $this->webhookFails = true;

        $service->queue($this->entry(1));
        foreach ($this->tasks as $task) {
            $task();
        }

        self::assertStringContainsString('error: Calling the redirect disable webhook failed: connection refused', implode("\n", $this->logged->getArrayCopy()));
    }

    public function testInTheCliNothingIsDeferred(): void
    {
        $service = $this->createService($this->webhook('text', 'single'));
        $this->useRealDeferral = true;

        $service->queue($this->entry(1));

        self::assertCount(1, $this->posts, 'no browser waits in the CLI, so it is sent at once');
    }
}
