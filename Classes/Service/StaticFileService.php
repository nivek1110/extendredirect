<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tells whether the target of a redirect is a file: a static file below the public path ("/fileadmin/docs/a.pdf")
 * or a FAL file link ("t3://file?uid=5"). Neither is a page, and the in-process frontend request of the health
 * check cannot serve static files - so they must be judged by the file itself, not by a page lookup or a request.
 *
 * Both methods answer with an HTTP-like status: 200 the file is there, 404 it is definitely missing, null it is
 * no file target (or could not be judged) and the caller should treat the target as before.
 */
class StaticFileService
{
    /**
     * @param string $path a URL path, optionally with a query string ("/fileadmin/a.pdf?download=1")
     */
    public function statusOfPath(string $path): ?int
    {
        // parse_url() would turn a null byte into "_", so look for it before and after decoding.
        if (str_contains($path, "\0")) {
            return null;
        }
        $path = rawurldecode((string) parse_url($path, PHP_URL_PATH));
        if ($path === '' || $path === '/' || str_contains($path, "\0")) {
            return null;
        }
        $path = '/' . ltrim($path, '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        $publicPath = rtrim(Environment::getPublicPath(), '/');
        if (is_file($publicPath . $path)) {
            return 200;
        }

        // Below the public prefix of a local file storage a missing file stays missing - it can never be a page.
        foreach ($this->getStoragePrefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return 404;
            }
        }

        return null;
    }

    /**
     * @return int|null null if the link is no "t3://file" link
     */
    public function statusOfFileLink(string $link): ?int
    {
        if (!str_starts_with($link, 't3://file')) {
            return null;
        }

        try {
            $details = GeneralUtility::makeInstance(LinkService::class)->resolve($link);
        } catch (\Throwable) {
            // The file record is gone.
            return 404;
        }

        $file = $details['file'] ?? null;
        if (!$file instanceof File) {
            return 404;
        }

        try {
            return !$file->isMissing() && $file->exists() ? 200 : 404;
        } catch (\Throwable) {
            // A storage that cannot be reached says nothing about the file: do not judge it.
            return null;
        }
    }

    /**
     * The public URL prefixes of the local file storages ("/fileadmin/"), plus the configured fileadmin directory.
     *
     * @return list<string>
     */
    private function getStoragePrefixes(): array
    {
        $prefixes = ['/' . trim((string) ($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'] ?? 'fileadmin/'), '/') . '/'];

        try {
            foreach (GeneralUtility::makeInstance(StorageRepository::class)->findAll() as $storage) {
                $configuration = $storage->getConfiguration();
                if ($storage->getDriverType() === 'Local' && ($configuration['pathType'] ?? '') === 'relative' && ($configuration['basePath'] ?? '') !== '') {
                    $prefixes[] = '/' . trim((string) $configuration['basePath'], '/') . '/';
                }
            }
        } catch (\Throwable) {
            // Without storage records only the fileadmin directory counts.
        }

        return array_values(array_unique($prefixes));
    }
}
