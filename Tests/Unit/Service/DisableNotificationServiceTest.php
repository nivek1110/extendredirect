<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Service;

use Nivek1110\Extendredirect\Service\DisableNotificationService;
use Nivek1110\Extendredirect\Tests\Support\RecordingMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class DisableNotificationServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $configuration
     */
    private function createService(RecordingMailer $mailer, array $configuration): DisableNotificationService
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        // The real mail is a Fluid template that needs the full TYPO3 environment; here only the
        // decision *whether and how often* to send is under test, so a plain Email stands in.
        return new class ($extensionConfiguration, $mailer, new NullLogger()) extends DisableNotificationService {
            protected function createEmail(array $entries, string $language): Email
            {
                return (new Email())->subject((string) count($entries))->text('x');
            }
        };
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
            'status_code' => '1',
            'reason' => 'The page was not found!',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enabled(string $mode = 'digest', string $recipients = 'ops@example.com'): array
    {
        return ['notifyOnDisable' => '1', 'notifyRecipients' => $recipients, 'notifyMode' => $mode, 'notifyLanguage' => 'en'];
    }

    public function testNothingIsSentByDefault(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, []);

        $service->queue($this->entry(1));
        $service->flush();

        self::assertSame([], $mailer->sent);
    }

    public function testNothingIsSentWhenTheOptionIsOff(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, ['notifyOnDisable' => '0', 'notifyRecipients' => 'ops@example.com']);

        $service->queue($this->entry(1));
        $service->flush();

        self::assertSame([], $mailer->sent);
    }

    public function testNothingIsSentWithoutAValidRecipient(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('digest', 'not-an-address, , also bad'));

        $service->queue($this->entry(1));
        $service->flush();

        self::assertSame([], $mailer->sent);
    }

    public function testDigestModeSendsOneMailWithAllQueuedRedirectsOnFlush(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('digest'));

        $service->queue($this->entry(1));
        $service->queue($this->entry(2));
        $service->queue($this->entry(3));
        self::assertSame([], $mailer->sent, 'nothing goes out before the flush');

        $service->flush();

        self::assertCount(1, $mailer->emails());
        self::assertSame('3', $mailer->emails()[0]->getSubject());
    }

    public function testFlushWithNothingQueuedAndASecondFlushSendNothing(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('digest'));

        $service->flush();
        $service->queue($this->entry(1));
        $service->flush();
        $service->flush();

        self::assertCount(1, $mailer->emails());
    }

    public function testSingleModeSendsOneMailPerRedirectImmediately(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('single'));

        $service->queue($this->entry(1));
        self::assertCount(1, $mailer->emails());
        $service->queue($this->entry(2));
        $service->queue($this->entry(3));
        $service->flush();

        self::assertCount(3, $mailer->emails());
        self::assertSame('1', $mailer->emails()[0]->getSubject());
    }

    public function testUnknownModeFallsBackToDigest(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('bogus'));

        $service->queue($this->entry(1));
        $service->queue($this->entry(2));
        $service->flush();

        self::assertCount(1, $mailer->emails());
    }

    public function testAllValidRecipientsAreAddressed(): void
    {
        $mailer = new RecordingMailer();
        $service = $this->createService($mailer, $this->enabled('digest', ' a@example.com , broken, b@example.com ,a@example.com'));

        $service->queue($this->entry(1));
        $service->flush();

        $addresses = array_map(static fn($address): string => $address->getAddress(), $mailer->emails()[0]->getTo());
        self::assertSame(['a@example.com', 'b@example.com'], $addresses);
    }

    public function testAFailingTransportNeverBreaksTheCaller(): void
    {
        $mailer = new RecordingMailer();
        $mailer->failWith = new TransportException('SMTP server unreachable');
        $service = $this->createService($mailer, $this->enabled('digest'));

        $service->queue($this->entry(1));
        $service->flush();

        $this->addToAssertionCount(1);
        // A failed digest is dropped, not retried on the next flush.
        $mailer->failWith = null;
        $service->flush();
        self::assertSame([], $mailer->sent);
    }

    public function testParseRecipientsTrimsValidatesAndDeduplicates(): void
    {
        self::assertSame(
            ['a@example.com', 'b@example.com'],
            DisableNotificationService::parseRecipients(' a@example.com;b@example.com, nope ,A@example.com ')
        );
        self::assertSame([], DisableNotificationService::parseRecipients(''));
    }
}
