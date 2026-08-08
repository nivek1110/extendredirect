<?php

namespace TWT\TwtRedirect\Service;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use TWT\TwtRedirect\Repository\DisableLogRepository;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\RequestHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\LinkHandling\PageLinkHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class HealthCheckService
{
    public const TABLE = 'sys_redirect';

    /**
     * Constructor
     *
     * @param ExtensionConfiguration $extensionConfiguration
     */
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration
    )
    {
    }

    /**
     * Health check
     *
     * @param bool|array<mixed> $redirect
     *
     * @return null|array<mixed>
     * @throws Exception
     */
    public function runHealthCheck(bool|array $redirect = false): array|null
    {
        if ($redirect) {
            $check = $this->checkRedirect($redirect);
            if ($redirect['disabled'] === 0) {
                $this->updateRedirect($redirect, $check);
            }
            return $check;
        }

        $redirects = $this->getRedirects();


        if (is_array($redirects)) {
            foreach ($redirects as $redirect) {
                $check = $this->checkRedirect($redirect);
                $this->updateRedirect($redirect, $check);
            }
        }
        return null;
    }

    /**
     * Find by SourcePath
     *
     * @param string|null $sourcePath
     * @param string      $sourceHost
     *
     * @return array<mixed>|null
     * @throws Exception
     */
    protected function findBySourcePath(string $sourcePath = null, string $sourceHost = '*'): ?array
    {
        if ($sourcePath === null) {
            return null;
        }
        $queryBuilder = $this->getQueryBuilder();

        if ($sourceHost !== '*') {
            return $queryBuilder
                ->select('*')
                ->from('sys_redirect')
                ->where(
                    $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath, ParameterType::STRING)),
                    $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter('*', ParameterType::STRING))
                )->orWhere(
                    $queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath, ParameterType::STRING)),
                        $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($sourceHost, ParameterType::STRING))
                    )
                )->executeQuery()->fetchAllAssociative();
        }

        return $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath, ParameterType::STRING))
            )->executeQuery()->fetchAllAssociative();
    }

    /**
     * Update Redirect
     *
     * @param array<mixed> $redirect
     * @param array<mixed> $check
     *
     * @return void
     */
    protected function updateRedirect(array $redirect, array $check): void
    {
        // Log the reason whenever the health check deactivates a redirect that
        // was still enabled before, so the disabling can be traced afterwards.
        if ($check['redirectError'] && (int)($redirect['disabled'] ?? 0) === 0) {
            $this->logDisable($redirect, $check);
        }

        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($redirect['uid'], ParameterType::INTEGER))
            )
            ->set('broken_redirect', (int)$check['redirectError'])
            ->set('redirect_count', $check['redirectCount'])
            ->set('disabled', (int)$check['redirectError'])
            ->set('target', $redirect['target'])
            ->executeStatement();

        $persistanceManager = GeneralUtility::makeInstance(PersistenceManager::class);
        $persistanceManager->persistAll();
    }

    /**
     * Writes a log entry explaining why a redirect was deactivated.
     *
     * @param array<mixed> $redirect
     * @param array<mixed> $check
     */
    protected function logDisable(array $redirect, array $check): void
    {
        $logRepository = GeneralUtility::makeInstance(DisableLogRepository::class);
        $logRepository->add([
            'pid' => 0,
            'crdate' => GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'),
            'redirect_uid' => (int)$redirect['uid'],
            'source_host' => (string)($redirect['source_host'] ?? ''),
            'source_path' => (string)($redirect['source_path'] ?? ''),
            'target' => (string)($redirect['target'] ?? ''),
            'status_code' => (string)($check['redirectCount'] ?? ''),
            'reason' => (string)($check['message'] ?? ''),
        ]);
    }

    protected function redirectFollower($location)
    {

        $request = GeneralUtility::makeInstance(ServerRequest::class);
        $subRequest = $request->withQueryParams([])->withUri(new Uri($location))->withMethod('GET');
        $requestHandler = GeneralUtility::makeInstance(RequestHandler::class);
        return $requestHandler->handle($subRequest);
    }

    /**
     * Check Redirect
     *
     * @param array<mixed> $redirect
     *
     * @return array<mixed>
     * @throws Exception
     */
    protected function checkRedirect(array &$redirect): array
    {
        $existRedirect = $this->findBySourcePath($redirect['source_path'], $redirect['source_host']);
        if ($existRedirect !== null && count($existRedirect) > 1) {
            return ['redirectError' => true, 'redirectCount' => '-', 'message' => 'Es existiert bereits eine Weiterleitung mit dem identischen Source Path.'];
        }

        $url = $this->resolveUrl($redirect);
        $response = null;

        if (isset($url)) {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);

            $redirected = [];
            $onRedirect = function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use (&$redirected) {
                $redirected[] = $uri->getPath();
            };

            $additionalOptions = [
                'headers' => ['Cache-Control' => 'no-cache'],
                'allow_redirects' => [
                    'strict' => true,      // use "strict" RFC compliant redirects.
                    'referer' => true,      // add a Referer header
                    'track_redirects' => true,
                    'on_redirect' => $onRedirect,
                ],
                'http_errors' => false,
                'verify' => false,
                'follow_location' => true,
            ];

            $origHttpSettings = $GLOBALS['TYPO3_CONF_VARS']['HTTP'];

            $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = array_merge($GLOBALS['TYPO3_CONF_VARS']['HTTP'], $additionalOptions);

            $basicAuth = $this->getBasicAuth();

            if ($basicAuth && array_key_exists('username', $basicAuth) && array_key_exists('password', $basicAuth)) {
                $auth = $basicAuth['username'] . ':' . $basicAuth['password'];
                $additionalOptions = [
                    'headers' => [
                        'Authorization' => 'BASIC ' . base64_encode($auth),
                    ],
                ];
            }

            $response = $this->redirectFollower($url);

            $redirectCount = 0;
            $maxRedirects = 10;
            $redirects = [];

            while ($response->hasHeader('Location') && $redirectCount < $maxRedirects) {

                // Den Location-Header auslesen (PSR-7 gibt Arrays zurück)
                $newLocation = $response->getHeader('location')[0];

                if (empty($newLocation)) {
                    break; // Falls der Header leer ist, abbrechen
                }

                $redirectCount++;
                $redirects[] = $newLocation;

                $response = $this->redirectFollower($newLocation);

                // Optional: Logge den Fortschritt
                // echo "Redirect #$redirectCount zu: $newLocation";
            }

            $addMessage = false;

            if ($response !== null && $response->getStatusCode() == 200) {
                if ($redirectCount > 1) {
                    $urlPath = parse_url($newLocation, PHP_URL_PATH);
                    $page = $this->getPageIdFromPath($urlPath);
                    if ($page !== null && is_array($page) && array_key_exists('uid', $page)) {
                        $linkHandler = GeneralUtility::makeInstance(PageLinkHandler::class);
                        $url = $linkHandler->asString(['pageuid' => $page['uid']]);
                        if ($url) {
                            $redirect['target'] = $url;
                            $redirectCount = 1;
                            $addMessage = true;
                        }
                    }
                }
                if ($addMessage && $redirectCount > 1) {
                    $mesage = 'Weiterleitungskette korrigiert';
                } else {
                    $mesage = '';
                }
                return ['redirectError' => false, 'redirectCount' => $redirectCount, 'message' => $mesage];
            } elseif ($redirectCount = $maxRedirects) {
                return ['redirectError' => true, 'redirectCount' => $redirectCount, 'message' => 'Es besteht die Gefahr einer Endlosschleife!'];
            } else {
                return ['redirectError' => true, 'redirectCount' => '-', 'message' => $response->getStatusCode() . ' - Seite nicht gefunden'];
            }
        }

        $statusCode = 500;

        return ['redirectError' => true, 'redirectCount' => '-', 'message' => $statusCode . ' - Seite nicht gefunden'];

    }

    /**
     * Resolve Url
     *
     * @param array<mixed> $redirect
     *
     * @return string|null
     */
    protected function resolveUrl(array $redirect): ?string
    {
        $site = $this->findSiteBySourceHost($redirect['source_host']);
        if ($site instanceof Site) {
            $host = $site->getBase()->getScheme() . '://' . $site->getBase()->getHost();
            $path = $redirect['source_path'] ?? null;
            return $host . $path;
        }
        return null;
    }

    /**
     * Get Page ID from path
     *
     * @param string $path
     *
     * @return array<mixed>|bool
     * @throws Exception
     */
    public function getPageIdFromPath(string $path): array|bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path, ParameterType::STRING)))
            ->executeQuery()
            ->fetchAssociative();

    }

    /**
     * Get redirects
     *
     * @return null|array
     * @throws Exception
     */
    protected function getRedirects(): ?array
    {
        $queryBuilder = $this->getQueryBuilder();
        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Find Site by SourceHost
     *
     * @param string $sourceHost
     *
     * @return Site|false
     */
    protected function findSiteBySourceHost(string $sourceHost): Site|false
    {
        $sites = GeneralUtility::makeInstance(SiteFinder::class);
        foreach ($sites->getAllSites() as $site) {
            if ($site->getBase()->getHost() === $sourceHost) {
                return $site;
            }
        }

        $allSites = $sites->getAllSites();
        return current($allSites);
    }

    /**
     * Get QueryBuilder
     *
     * @return QueryBuilder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
    }

    /**
     * Get Basic Auth
     *
     * @return array{username: string, password: string}
     *
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    protected function getBasicAuth(): array
    {
        $username = $this->extensionConfiguration->get('twt_redirect', 'username');
        $password = $this->extensionConfiguration->get('twt_redirect', 'password');

        $basicAuth = [];
        $basicAuth['username'] = $username;
        $basicAuth['password'] = $password;

        return $basicAuth;
    }

}
