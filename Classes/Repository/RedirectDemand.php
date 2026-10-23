<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Repository;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Demand Object for filtering redirects in the backend module
 * @internal
 */
class RedirectDemand extends \TYPO3\CMS\Redirects\Repository\Demand
{
    public const BROKEN_REDIRECT_ALL = 0;

    /**
     * @var int
     */
    protected $brokenRedirect = self::BROKEN_REDIRECT_ALL;

    /**
     * TYPO3 14's core Demand takes a "redirectType" argument right after the order direction
     * (and defines DEFAULT_REDIRECT_TYPE), 13.4's has neither. Our own signature therefore leaves it
     * out and hands the default to the parent only where the parent expects it.
     *
     * @param int $page
     * @param string $orderField
     * @param string $orderDirection
     * @param array<mixed> $sourceHosts
     * @param string $sourcePath
     * @param string $target
     * @param array<mixed> $statusCodes
     * @param int $maxHits
     * @param \DateTimeInterface|null $olderThan
     * @param int|null $creationType
     * @param int|null $protected
     * @param string|null $integrityStatus
     * @param int $brokenRedirect
     */
    public function __construct(int $page = 1, string $orderField = self::DEFAULT_ORDER_FIELD, string $orderDirection = self::ORDER_ASCENDING, array $sourceHosts = [], string $sourcePath = '', string $target = '', array $statusCodes = [], int $maxHits = 0, ?\DateTimeInterface $olderThan = null, ?int $creationType = -1, ?int $protected = -1, ?string $integrityStatus = null, int $brokenRedirect = 0)
    {
        $coreOnlyArguments = (new Typo3Version())->getMajorVersion() >= 14 ? ['redirectType' => 'default'] : [];

        parent::__construct(
            ...$coreOnlyArguments,
            page: $page,
            orderField: $orderField,
            orderDirection: $orderDirection,
            sourceHosts: $sourceHosts,
            sourcePath: $sourcePath,
            target: $target,
            statusCodes: $statusCodes,
            maxHits: $maxHits,
            olderThan: $olderThan,
            creationType: $creationType,
            protected: $protected,
            integrityStatus: $integrityStatus
        );
        $this->brokenRedirect = $brokenRedirect;
    }

    /**
     * Creates a Demand object from the current request.
     *
     * @param ServerRequestInterface $request
     * @return RedirectDemand
     */
    public static function createFromRequest(ServerRequestInterface $request): self
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $parsedBody = is_array($parsedBody) ? $parsedBody : [];

        $page = (int) ($queryParams['page'] ?? $parsedBody['page'] ?? 1);
        $orderField = $queryParams['orderField'] ?? $parsedBody['orderField'] ?? self::DEFAULT_ORDER_FIELD;
        $orderDirection = $queryParams['orderDirection'] ?? $parsedBody['orderDirection'] ?? self::ORDER_ASCENDING;
        $requestDemand = $queryParams['demand'] ?? $parsedBody['demand'] ?? [];

        if (empty($requestDemand)) {
            return new self($page, $orderField, $orderDirection);
        }

        $sourceHost = $requestDemand['source_host'] ?? '';
        $sourceHosts = $sourceHost ? [$sourceHost] : [];
        $sourcePath = $requestDemand['source_path'] ?? '';
        $statusCode = (int) ($requestDemand['target_statuscode'] ?? 0);
        $statusCodes = $statusCode > 0 ? [$statusCode] : [];
        $target = $requestDemand['target'] ?? '';
        $maxHits = (int) ($requestDemand['max_hits'] ?? 0);
        $brokenRedirect = (int) ($requestDemand['broken_redirect'] ?? 0);

        return new self($page, $orderField, $orderDirection, $sourceHosts, $sourcePath, $target, $statusCodes, $maxHits, null, -1, -1, null, $brokenRedirect);
    }

    /**
     * @return int
     */
    public function getBrokenRedirect(): int
    {
        return $this->brokenRedirect;
    }

    /**
     * @return bool
     */
    public function hasBrokenRedirect(): bool
    {
        return $this->brokenRedirect !== 0;
    }

    /**
     * @param int $brokenRedirect
     */
    public function setBrokenRedirect(int $brokenRedirect): void
    {
        $this->brokenRedirect = $brokenRedirect;
    }

    /**
     * @return bool
     */
    public function hasConstraints(): bool
    {
        return parent::hasConstraints() || $this->hasBrokenRedirect();
    }

    /**
     * @return array<mixed>
     */
    public function getParameters(): array
    {
        $parameters = [];
        if ($this->hasSourcePath()) {
            $parameters['source_path'] = $this->getSourcePath();
        }
        if ($this->hasSourceHosts()) {
            $parameters['source_host'] = $this->getFirstSourceHost();
        }
        if ($this->hasTarget()) {
            $parameters['target'] = $this->getTarget();
        }
        if ($this->hasStatusCodes()) {
            $parameters['target_statuscode'] = $this->getFirstStatusCode();
        }
        if ($this->hasMaxHits()) {
            $parameters['max_hits'] = $this->getMaxHits();
        }
        if ($this->hasBrokenRedirect()) {
            $parameters['broken_redirect'] = $this->getBrokenRedirect();
        }
        return $parameters;
    }
}
