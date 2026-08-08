<?php

declare(strict_types=1);

namespace TWT\TwtRedirect\Repository;

use Psr\Http\Message\ServerRequestInterface;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Redirects\Repository\Demand;

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

    public function countRedirectsByRbDemand(RedirectDemand|Demand $demand): int
    {
        return (int)$this->getQueryBuilderForRbDemand($demand, true)
            ->executeQuery()
            ->fetchOne();
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
