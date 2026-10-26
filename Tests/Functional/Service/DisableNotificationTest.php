<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Repository\RedirectRepository;
use Nivek1110\Extendredirect\Service\DisableNotificationService;
use Nivek1110\Extendredirect\Service\HealthCheckService;
use Nivek1110\Extendredirect\Service\PageRedirectGuardService;
use Nivek1110\Extendredirect\Tests\Support\RecordingMailer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The optional "redirect disabled" mail, end to end: from the health check and the page
 * guard through the real Fluid templates, with only the mailer replaced by a recorder.
 */
class DisableNotificationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private RecordingMailer $mailer;
    private int $rootUid;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
        $this->configure([]);

        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $this->rootUid = (int) $connection->lastInsertId();
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', $this->rootUid, 'https://example.com/');

        $this->mailer = new RecordingMailer();
        GeneralUtility::setSingletonInstance(
            DisableNotificationService::class,
            new DisableNotificationService(
                GeneralUtility::makeInstance(ExtensionConfiguration::class),
                $this->mailer,
                new NullLogger(),
                GeneralUtility::makeInstance(EventDispatcherInterface::class)
            )
        );
    }

    protected function tearDown(): void
    {
        GeneralUtility::removeSingletonInstance(
            DisableNotificationService::class,
            GeneralUtility::makeInstance(DisableNotificationService::class)
        );
        parent::tearDown();
    }

    /**
     * @param array<string, string> $notification
     */
    private function configure(array $notification): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extendredirect'] = array_merge(['checkMode' => 'database'], $notification);
    }

    /**
     * @return array<string, string>
     */
    private function enabled(string $mode = 'digest', string $language = 'en'): array
    {
        return [
            'notifyOnDisable' => '1',
            'notifyRecipients' => 'ops@example.com, admin@example.com',
            'notifyMode' => $mode,
            'notifyLanguage' => $language,
        ];
    }

    private function insertRedirect(string $sourcePath, string $target): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_redirect');
        $connection->insert('sys_redirect', [
            'pid' => 0,
            'source_host' => '*',
            'source_path' => $sourcePath,
            'target' => $target,
            'target_statuscode' => 301,
            'disabled' => 0,
            'is_regexp' => 0,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function insertPage(string $slug): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        $connection->insert('pages', ['pid' => $this->rootUid, 'title' => $slug, 'slug' => $slug, 'doktype' => 1]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return list<Email>
     */
    private function mails(): array
    {
        return $this->mailer->emails();
    }

    public function testBulkCheckSendsOneDigestMailForAllDisabledRedirects(): void
    {
        $this->configure($this->enabled('digest'));
        $this->insertRedirect('/old-a', '/missing-a');
        $this->insertRedirect('/old-b', '/missing-b');
        $this->insertPage('/fine');
        $this->insertRedirect('/old-ok', '/fine');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        self::assertCount(1, $this->mails());
        $mail = $this->mails()[0];
        self::assertSame('2 redirects were disabled', $mail->getSubject());
        self::assertSame(['ops@example.com', 'admin@example.com'], array_map(static fn($a): string => $a->getAddress(), $mail->getTo()));
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('/old-a', $text);
        self::assertStringContainsString('/missing-b', $text);
        // "database" check mode: the reason is the page-not-found label.
        self::assertStringContainsString('Page not found', $text);
        self::assertStringNotContainsString('/old-ok', $text, 'a healthy redirect is not reported');
    }

    public function testSingleModeSendsOneMailPerDisabledRedirect(): void
    {
        $this->configure($this->enabled('single'));
        $this->insertRedirect('/old-a', '/missing-a');
        $this->insertRedirect('/old-b', '/missing-b');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        self::assertCount(2, $this->mails());
        self::assertStringContainsString('Redirect disabled: */old-a', (string) $this->mails()[0]->getSubject());
        self::assertStringContainsString('/old-a', (string) $this->mails()[0]->getTextBody());
        self::assertStringNotContainsString('/old-b', (string) $this->mails()[0]->getTextBody());
    }

    public function testNoMailIsSentWhileTheOptionIsOff(): void
    {
        $this->configure(['notifyOnDisable' => '0', 'notifyRecipients' => 'ops@example.com']);
        $this->insertRedirect('/old-a', '/missing-a');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        self::assertSame([], $this->mails());
    }

    public function testDryRunNeverSendsAMail(): void
    {
        $this->configure($this->enabled('digest'));
        $this->insertRedirect('/old-a', '/missing-a');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck(true);

        self::assertSame([], $this->mails());
    }

    public function testHidingAPageSendsOneMailForAllItsRedirects(): void
    {
        $this->configure($this->enabled('digest'));
        $pageUid = $this->insertPage('/target-page');
        $this->insertRedirect('/from-a', 't3://page?uid=' . $pageUid);
        $this->insertRedirect('/from-b', 't3://page?uid=' . $pageUid);

        GeneralUtility::makeInstance(PageRedirectGuardService::class)
            ->disableRedirectsTargetingPage($pageUid, 'healthCheck.targetPageHidden');

        self::assertCount(1, $this->mails());
        $text = (string) $this->mails()[0]->getTextBody();
        self::assertStringContainsString('/from-a', $text);
        self::assertStringContainsString('/from-b', $text);
        self::assertStringContainsString('The target page was hidden.', $text);
    }

    public function testMailIsRenderedInTheConfiguredLanguage(): void
    {
        $this->configure($this->enabled('digest', 'de'));
        $this->insertRedirect('/old-a', '/missing-a');
        $this->insertRedirect('/old-b', '/missing-b');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        $mail = $this->mails()[0];
        self::assertSame('2 Redirects wurden deaktiviert', $mail->getSubject());
        self::assertStringContainsString('deaktiviert', (string) $mail->getTextBody());
    }

    public function testHtmlBodyEscapesRedirectValuesAndTextBodyKeepsThemReadable(): void
    {
        $this->configure($this->enabled('digest'));
        $this->insertRedirect('/old?a=1&b=<script>', '/missing');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        $mail = $this->mails()[0];
        self::assertStringNotContainsString('<script>', (string) $mail->getHtmlBody());
        self::assertStringContainsString('&lt;script&gt;', (string) $mail->getHtmlBody());
        self::assertStringContainsString('/old?a=1&b=<script>', (string) $mail->getTextBody());
    }

    public function testAFailingMailTransportDoesNotStopTheCheck(): void
    {
        $this->configure($this->enabled('digest'));
        $this->mailer->failWith = new TransportException('SMTP server unreachable');
        $uid = $this->insertRedirect('/old-a', '/missing-a');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        $row = GeneralUtility::makeInstance(RedirectRepository::class)->findByUid($uid);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['disabled']);
    }

    public function testPlainTextBodyContainsNoHtmlEntities(): void
    {
        $this->configure($this->enabled('digest'));
        $this->insertRedirect('/old-a', '/missing-a');

        GeneralUtility::makeInstance(HealthCheckService::class)->runBulkCheck();

        $text = (string) $this->mails()[0]->getTextBody();
        self::assertDoesNotMatchRegularExpression('/&(quot|amp|lt|gt|#\d+);/', $text);
        self::assertStringContainsString('"Disable log"', $text);
    }
}
