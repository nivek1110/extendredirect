<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Support;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Static files below the public path and FAL files for the functional tests.
 */
trait CreatesFiles
{
    /** @var list<string> */
    private array $createdPublicFiles = [];

    private function createPublicFile(string $relativePath): void
    {
        $absolute = Environment::getPublicPath() . '/' . ltrim($relativePath, '/');
        GeneralUtility::mkdir_deep(dirname($absolute));
        file_put_contents($absolute, 'test');
        $this->createdPublicFiles[] = $absolute;
    }

    private function removePublicFile(string $relativePath): void
    {
        $absolute = Environment::getPublicPath() . '/' . ltrim($relativePath, '/');
        if (is_file($absolute)) {
            unlink($absolute);
        }
    }

    private function removeCreatedPublicFiles(): void
    {
        foreach ($this->createdPublicFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->createdPublicFiles = [];
    }

    /**
     * Creates a file in a local "fileadmin" storage and returns its sys_file uid.
     */
    private function createFalFile(string $name): int
    {
        $repository = GeneralUtility::makeInstance(StorageRepository::class);
        $storage = $repository->findByUid(1) ?? $repository->getStorageObject($repository->createLocalStorage('fileadmin', 'fileadmin', 'relative', '', true));
        $storage->setEvaluatePermissions(false);
        $file = $storage->createFile($name, $storage->getRootLevelFolder());
        if (!$file instanceof File) {
            throw new \RuntimeException('The test file could not be created.');
        }
        $this->createdPublicFiles[] = Environment::getPublicPath() . '/fileadmin/' . $name;

        return $file->getUid();
    }
}
