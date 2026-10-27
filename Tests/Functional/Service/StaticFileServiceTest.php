<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Service;

use Nivek1110\Extendredirect\Service\StaticFileService;
use Nivek1110\Extendredirect\Tests\Support\CreatesFiles;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class StaticFileServiceTest extends FunctionalTestCase
{
    use CreatesFiles;

    protected array $coreExtensionsToLoad = ['redirects', 'scheduler'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private StaticFileService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(StaticFileService::class);
    }

    protected function tearDown(): void
    {
        $this->removeCreatedPublicFiles();
        parent::tearDown();
    }

    public function testAnExistingFileBelowThePublicPathIsFound(): void
    {
        $this->createPublicFile('fileadmin/docs/test.pdf');

        self::assertSame(200, $this->subject->statusOfPath('/fileadmin/docs/test.pdf'));
        self::assertSame(200, $this->subject->statusOfPath('/fileadmin/docs/test.pdf?download=1'), 'a query string is ignored');
    }

    public function testAMissingFileInTheFileStorageIsDefinitelyMissing(): void
    {
        self::assertSame(404, $this->subject->statusOfPath('/fileadmin/docs/gone.pdf'));
    }

    public function testAFileNameWithEncodedCharactersIsDecoded(): void
    {
        $this->createPublicFile('fileadmin/docs/mein bericht.pdf');

        self::assertSame(200, $this->subject->statusOfPath('/fileadmin/docs/mein%20bericht.pdf'));
    }

    public function testAnyOtherStaticFileBelowThePublicPathIsFoundToo(): void
    {
        $this->createPublicFile('robots.txt');

        self::assertSame(200, $this->subject->statusOfPath('/robots.txt'));
    }

    public function testAPathThatIsNoFileAndOutsideTheStorageIsUnknown(): void
    {
        self::assertNull($this->subject->statusOfPath('/produkte/schuhe'), 'might be a page');
        self::assertNull($this->subject->statusOfPath('/'));
        self::assertNull($this->subject->statusOfPath(''));
    }

    public function testADirectoryIsNoFile(): void
    {
        $this->createPublicFile('fileadmin/docs/test.pdf');

        self::assertSame(404, $this->subject->statusOfPath('/fileadmin/docs'), 'inside the storage a directory is not a file target');
        self::assertNull($this->subject->statusOfPath('/typo3temp'), 'outside the storage a directory could be a page');
    }

    public function testATraversalOutOfThePublicPathIsNeverAFile(): void
    {
        self::assertNull($this->subject->statusOfPath('/fileadmin/../../etc/passwd'));
        self::assertNull($this->subject->statusOfPath('/fileadmin/%2e%2e/%2e%2e/etc/passwd'));
        self::assertNull($this->subject->statusOfPath("/fileadmin/docs/test.pdf\0.jpg"));
    }

    public function testAFileLinkToAnExistingFileIsFound(): void
    {
        $uid = $this->createFalFile('linked.pdf');

        self::assertSame(200, $this->subject->statusOfFileLink('t3://file?uid=' . $uid));
    }

    public function testAFileLinkToAFileThatIsGoneFromTheDiskIsMissing(): void
    {
        $uid = $this->createFalFile('vanished.pdf');
        $this->removePublicFile('fileadmin/vanished.pdf');

        self::assertSame(404, $this->subject->statusOfFileLink('t3://file?uid=' . $uid));
    }

    public function testAFileLinkToAnUnknownFileIsMissing(): void
    {
        self::assertSame(404, $this->subject->statusOfFileLink('t3://file?uid=99999'));
    }

    public function testOtherLinksAreNoFileLinks(): void
    {
        self::assertNull($this->subject->statusOfFileLink('t3://page?uid=1'));
        self::assertNull($this->subject->statusOfFileLink('/fileadmin/test.pdf'));
        self::assertNull($this->subject->statusOfFileLink('https://example.com/'));
    }
}
