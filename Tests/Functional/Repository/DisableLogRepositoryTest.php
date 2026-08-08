<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Functional\Repository;

use Nivek1110\Extendredirect\Repository\DisableLogRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class DisableLogRepositoryTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['nivek1110/extendredirect'];

    private DisableLogRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = GeneralUtility::makeInstance(DisableLogRepository::class);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function logEntry(array $overrides = []): array
    {
        return array_merge([
            'pid' => 0,
            'crdate' => 1000,
            'redirect_uid' => 1,
            'source_host' => 'example.com',
            'source_path' => '/foo',
            'target' => '/bar',
            'status_code' => '404',
            'reason' => 'not found',
        ], $overrides);
    }

    public function testAddPersistsEntry(): void
    {
        $this->subject->add($this->logEntry(['redirect_uid' => 42]));

        $all = $this->subject->findAll();
        self::assertCount(1, $all);
        self::assertSame(42, (int) $all[0]['redirect_uid']);
    }

    public function testFindAllOrdersNewestFirst(): void
    {
        $this->subject->add($this->logEntry(['crdate' => 100, 'redirect_uid' => 1]));
        $this->subject->add($this->logEntry(['crdate' => 200, 'redirect_uid' => 2]));

        $all = $this->subject->findAll();

        self::assertSame(2, (int) $all[0]['redirect_uid']);
        self::assertSame(1, (int) $all[1]['redirect_uid']);
    }

    public function testFindPaginatedReturnsRequestedSlice(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->subject->add($this->logEntry(['crdate' => $i, 'redirect_uid' => $i]));
        }

        $page = $this->subject->findPaginated(2, 0);

        self::assertCount(2, $page);
        self::assertSame(5, (int) $page[0]['redirect_uid']);
        self::assertSame(4, (int) $page[1]['redirect_uid']);
    }

    public function testCountAll(): void
    {
        self::assertSame(0, $this->subject->countAll());

        $this->subject->add($this->logEntry());
        $this->subject->add($this->logEntry());

        self::assertSame(2, $this->subject->countAll());
    }

    public function testDeleteByUidRemovesOnlyThatEntry(): void
    {
        $this->subject->add($this->logEntry(['redirect_uid' => 1]));
        $this->subject->add($this->logEntry(['redirect_uid' => 2]));

        $uidToDelete = (int) $this->subject->findAll()[0]['uid'];
        $this->subject->deleteByUid($uidToDelete);

        self::assertSame(1, $this->subject->countAll());
    }

    public function testDeleteAllRemovesEveryEntry(): void
    {
        $this->subject->add($this->logEntry());
        $this->subject->add($this->logEntry());

        $this->subject->deleteAll();

        self::assertSame(0, $this->subject->countAll());
    }
}
