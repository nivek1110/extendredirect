<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Repository;

use Psr\Http\Message\ServerRequestInterface;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

/**
 * Demand Object for filtering redirects in the backend module
 * @internal
 */
class RedirectRepository extends \TYPO3\CMS\Redirects\Repository\RedirectRepository
{
    /**
     * @param RedirectDemand $demand
     * @return array<mixed>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findRedirectsByRbDemand(RedirectDemand $demand): array
    {
        return $this->getQueryBuilderForRbDemand($demand)
            ->setMaxResults($demand->getLimit())
            ->setFirstResult($demand->getOffset())
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Looks up a redirect regardless of its disabled/hidden state - callers
     * that just saved a record (e.g. DataHandlerHook, right after an insert or
     * update) need to find it even if it's currently marked disabled/broken,
     * since that's often exactly the state a health check is meant to re-evaluate.
     *
     * @return array<mixed>|bool
     * @throws \Doctrine\DBAL\Exception
     */
    public function findByUid(int $uid): array|bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER))
            )->executeQuery();

        return $result->fetchAssociative();
    }

    /**
     * Finds every non-deleted redirect whose target either exactly matches
     * $t3Link (a "t3://page?uid=..." link) or, as a plain path/URL, contains
     * $slug - used to find redirects pointing at a page that's about to be
     * deleted or hidden.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findRedirectsTargetingPage(string $t3Link, ?string $slug): array
    {
        $queryBuilder = $this->getQueryBuilder();

        // The page link itself, also with parameters ("&_language=1") or an anchor ("#c12") behind it.
        $conditions = [
            $queryBuilder->expr()->eq('target', $queryBuilder->createNamedParameter($t3Link)),
            $queryBuilder->expr()->like('target', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($t3Link) . '&%')),
            $queryBuilder->expr()->like('target', $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($t3Link) . '#%')),
        ];
        // The root slug "/" ends nearly every path: only the page link can tell a redirect to the root page.
        if ($slug !== null && $slug !== '' && trim($slug, '/') !== '') {
            $conditions[] = $queryBuilder->expr()->like(
                'target',
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($slug))
            );
        }

        return $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where($queryBuilder->expr()->or(...$conditions))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Marks a redirect disabled/broken - used once its target no longer exists.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function disable(int $uid): void
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->update('sys_redirect')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->set('disabled', 1)
            ->set('broken_redirect', 1)
            ->executeStatement();
    }

    /**
     * Whether a redirect with this source path already exists. Without a host,
     * any host counts; with one, only that host and wildcard ('*') redirects do -
     * a wildcard redirect would match that domain at runtime as well, same as
     * in findDuplicatesBySourcePath().
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function existsBySourcePath(string $sourcePath, ?string $sourceHost = null): bool
    {
        if ($sourceHost !== null) {
            return $this->findDuplicatesBySourcePath($sourcePath, $sourceHost) !== [];
        }

        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder
            ->select('uid')
            ->from('sys_redirect')
            ->where($queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath)))
            ->executeQuery()
            ->fetchAssociative() !== false;
    }

    /**
     * Every redirect, regardless of disabled/hidden state - the health check
     * needs to re-evaluate all of them, including already-disabled ones.
     * Never-checked redirects come first, then the least recently checked, so
     * a limited bulk run rotates through all of them over time.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findAll(): array
    {
        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->orderBy('last_checked', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Finds other redirects sharing the same source_path - used to flag
     * ambiguous/duplicate redirects during a health check. A wildcard ('*')
     * source_host is checked against every host; a specific one is checked
     * against both itself and '*' (since a wildcard redirect would also match
     * that domain at runtime).
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findDuplicatesBySourcePath(string $sourcePath, string $sourceHost): array
    {
        $queryBuilder = $this->getQueryBuilder();

        if ($sourceHost === '*') {
            return $queryBuilder
                ->select('*')
                ->from('sys_redirect')
                ->where(
                    $queryBuilder->expr()->eq('source_path', $queryBuilder->createNamedParameter($sourcePath, ParameterType::STRING))
                )->executeQuery()->fetchAllAssociative();
        }

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

    /**
     * Persists a health check's outcome onto the redirect.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function updateHealthCheckResult(int $uid, bool $broken, int|string $redirectCount, string $target): void
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->update('sys_redirect')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->set('broken_redirect', (int) $broken)
            ->set('redirect_count', $redirectCount)
            ->set('last_checked', (int) ($GLOBALS['EXEC_TIME'] ?? time()))
            ->set('disabled', (int) $broken)
            ->set('target', $target)
            ->executeStatement();
    }

    /**
     * Finds another, non-disabled redirect whose source matches the given slug -
     * either a flat, literal source_path, or a regexp one preg_match()es it -
     * scoped to the same host (or a wildcard '*' one, which matches any host).
     * Used to follow a redirect chain purely against the database.
     *
     * @return array<string, mixed>|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function findChainingRedirect(string $slug, string $host, int $excludeUid): ?array
    {
        $flatQueryBuilder = $this->getQueryBuilder();
        $flatMatch = $flatQueryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $flatQueryBuilder->expr()->eq('disabled', 0),
                $flatQueryBuilder->expr()->eq('is_regexp', 0),
                $flatQueryBuilder->expr()->neq('uid', $flatQueryBuilder->createNamedParameter($excludeUid, ParameterType::INTEGER)),
                $flatQueryBuilder->expr()->eq('source_path', $flatQueryBuilder->createNamedParameter($slug, ParameterType::STRING)),
                $flatQueryBuilder->expr()->or(
                    $flatQueryBuilder->expr()->eq('source_host', $flatQueryBuilder->createNamedParameter($host, ParameterType::STRING)),
                    $flatQueryBuilder->expr()->eq('source_host', $flatQueryBuilder->createNamedParameter('*', ParameterType::STRING))
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($flatMatch !== false) {
            return $flatMatch;
        }

        $regexQueryBuilder = $this->getQueryBuilder();
        $regexCandidates = $regexQueryBuilder
            ->select('*')
            ->from('sys_redirect')
            ->where(
                $regexQueryBuilder->expr()->eq('disabled', 0),
                $regexQueryBuilder->expr()->eq('is_regexp', 1),
                $regexQueryBuilder->expr()->neq('uid', $regexQueryBuilder->createNamedParameter($excludeUid, ParameterType::INTEGER)),
                $regexQueryBuilder->expr()->or(
                    $regexQueryBuilder->expr()->eq('source_host', $regexQueryBuilder->createNamedParameter($host, ParameterType::STRING)),
                    $regexQueryBuilder->expr()->eq('source_host', $regexQueryBuilder->createNamedParameter('*', ParameterType::STRING))
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($regexCandidates as $candidate) {
            if (@preg_match((string) $candidate['source_path'], $slug) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Prepares the QueryBuilder with Constraints from the Demand
     */
    protected function getQueryBuilderForRbDemand(RedirectDemand $demand, bool $createCountQuery = false): QueryBuilder
    {
        $queryBuilder = $this->getQueryBuilder();

        if ($createCountQuery) {
            $queryBuilder->count('*');
        } else {
            $queryBuilder->select('*');
        }
        $queryBuilder
            ->from('sys_redirect');

        $queryBuilder->orderBy(
            $demand->getOrderField(),
            $demand->getOrderDirection()
        );

        if ($demand->hasSecondaryOrdering()) {
            $queryBuilder->addOrderBy($demand->getSecondaryOrderField());
        }

        $constraints = [];
        if ($demand->hasSourceHosts()) {
            $constraints[] = $queryBuilder->expr()->in(
                'source_host',
                $queryBuilder->createNamedParameter($demand->getSourceHosts(), Connection::PARAM_STR_ARRAY)
            );
        }

        if ($demand->hasSourcePath()) {
            $escapedLikeString = '%' . $queryBuilder->escapeLikeWildcards($demand->getSourcePath()) . '%';
            $constraints[] = $queryBuilder->expr()->like(
                'source_path',
                $queryBuilder->createNamedParameter($escapedLikeString, ParameterType::STRING)
            );
        }

        if ($demand->hasTarget()) {
            $escapedLikeString = '%' . $queryBuilder->escapeLikeWildcards($demand->getTarget()) . '%';
            $constraints[] = $queryBuilder->expr()->like(
                'target',
                $queryBuilder->createNamedParameter($escapedLikeString, ParameterType::STRING)
            );
        }

        if ($demand->hasStatusCodes()) {
            $constraints[] = $queryBuilder->expr()->in(
                'target_statuscode',
                $queryBuilder->createNamedParameter($demand->getStatusCodes(), Connection::PARAM_INT_ARRAY)
            );
        }

        if ($demand->hasMaxHits()) {
            $constraints[] = $queryBuilder->expr()->lt(
                'hitcount',
                $queryBuilder->createNamedParameter($demand->getMaxHits(), ParameterType::INTEGER)
            );
            // When max hits is set, exclude records which explicitly disabled the hitcount feature
            $constraints[] = $queryBuilder->expr()->eq(
                'disable_hitcount',
                $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
            );
        }

        if ($demand->hasLastChecked()) {
            if ($demand->getLastChecked() === RedirectDemand::LAST_CHECKED_NEVER) {
                $constraints[] = $queryBuilder->expr()->eq(
                    'last_checked',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)
                );
            } else {
                // "staleN": not checked for N days - redirects that were never checked count as well
                $days = (int) substr($demand->getLastChecked(), strlen('stale'));
                $constraints[] = $queryBuilder->expr()->lt(
                    'last_checked',
                    $queryBuilder->createNamedParameter(
                        (int) ($GLOBALS['EXEC_TIME'] ?? time()) - $days * 86400,
                        ParameterType::INTEGER
                    )
                );
            }
        }

        if ($demand->hasBrokenRedirect()) {
            $constraints[] = $queryBuilder->expr()->in(
                'broken_redirect',
                $queryBuilder->createNamedParameter($demand->getBrokenRedirect(), ParameterType::INTEGER)
            );
        }

        if (!empty($constraints)) {
            $queryBuilder->where(...$constraints);
        }

        return $queryBuilder;
    }
}
