<?php

declare(strict_types=1);

namespace TWT\TwtRedirect\Repository;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Redirects\Repository\Demand;

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
     * @param int $page
     * @param string $orderField
     * @param string $orderDirection
     * @param array<mixed> $sourceHosts
     * @param string $sourcePath
     * @param string $target
     * @param array<mixed> $statusCodes
     * @param int $maxHits
     * @param \DateTimeInterface|null $olderThan
     * @param int $brokenRedirect
     */
    public function __construct(int $page = 1, string $orderField = self::DEFAULT_ORDER_FIELD, string $orderDirection = self::ORDER_ASCENDING, array $sourceHosts = [], string $sourcePath = '', string $target = '', array $statusCodes = [], int $maxHits = 0, \DateTimeInterface $olderThan = null, int $brokenRedirect = 0)
    {
        parent::__construct($page, $orderField, $orderDirection, $sourceHosts, $sourcePath, $target, $statusCodes, $maxHits, $olderThan);
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
        $page = (int)($request->getQueryParams()['page'] ?? $request->getParsedBody()['page'] ?? 1);
        $orderField = $request->getQueryParams()['orderField'] ?? $request->getParsedBody()['orderField'] ?? self::DEFAULT_ORDER_FIELD;
        $orderDirection = $request->getQueryParams()['orderDirection'] ?? $request->getParsedBody()['orderDirection'] ?? self::ORDER_ASCENDING;
        $requestDemand = $request->getQueryParams()['demand'] ?? $request->getParsedBody()['demand'] ?? [];

        if (empty($requestDemand)) {
            return new self($page, $orderField, $orderDirection);
        }

        $sourceHost = $requestDemand['source_host'] ?? '';
        $sourceHosts = $sourceHost ? [$sourceHost] : [];
        $sourcePath = $requestDemand['source_path'] ?? '';
        $statusCode = (int)($requestDemand['target_statuscode'] ?? 0);
        $statusCodes = $statusCode > 0 ? [$statusCode] : [];
        $target = $requestDemand['target'] ?? '';
        $maxHits = (int)($requestDemand['max_hits'] ?? 0);
        $brokenRedirect = (int)($requestDemand['broken_redirect'] ?? 0);

        $demand = new self($page, $orderField, $orderDirection, $sourceHosts, $sourcePath, $target, $statusCodes, $maxHits, null, $brokenRedirect);
        $demand->setBrokenRedirect($brokenRedirect);

        return $demand;
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
