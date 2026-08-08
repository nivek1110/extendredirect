<?php

declare(strict_types=1);

namespace TWT\TwtRedirect\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repository for the redirect disable log, holding the reasons why the
 * scheduler task deactivated a redirect.
 */
class DisableLogRepository
{
    public const TABLE = 'tx_twtredirect_disable_log';

    /**
     * Returns all log entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findAll(): array
    {
        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Returns a single page of log entries, newest first.
     *
     * @return array<int, array<string, mixed>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function findPaginated(int $limit, int $offset): array
    {
        $queryBuilder = $this->getQueryBuilder();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Counts all log entries.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function countAll(): int
    {
        $queryBuilder = $this->getQueryBuilder();

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Persists a new log entry.
     *
     * @param array<string, mixed> $values
     * @throws \Doctrine\DBAL\Exception
     */
    public function add(array $values): void
    {
        $this->getConnection()->insert(self::TABLE, $values);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteByUid(int $uid): void
    {
        $this->getConnection()->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteAll(): void
    {
        $this->getConnection()->truncate(self::TABLE);
    }

    protected function getConnection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
    }

    protected function getQueryBuilder(): QueryBuilder
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
    }
}
