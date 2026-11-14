<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter;

use Bitrix\Main\Application;
use Bitrix\Rest\V3\Limiter\Config\LoadLimiterConfig;
use Bitrix\Rest\V3\Limiter\Config\LoadLimiterConfigBitrix24;
use Bitrix\Rest\V3\Limiter\Config\QueryTimeLimiterConfig;

class LimiterChainFactory
{
	private array $chains = [];
	private ?array $loadLimiterSettings = null;
	private ?QueryTimeLimiter $queryTimeLimiter = null;

	public function get(?string $entityType, ?string $entity, ?string $method, bool $isLoadLimited = true): LimiterChain
	{
		$key = $entityType . '|' . $entity . '|' . $method . '|' . (int)$isLoadLimited;

		return $this->chains[$key] ??= new LimiterChain(...array_filter([
			$isLoadLimited ? $this->createLoadLimiter($entityType, $entity, $method) : null,
			$this->queryTimeLimiter ??= $this->createQueryTimeLimiter(),
		]));
	}

	protected function createLoadLimiter(?string $entityType, ?string $entity, ?string $method): LoadLimiter
	{
		[$resource, $isActive, $limitTime, $domain] = $this->loadLimiterSettings ??= $this->readLoadLimiterSettings();

		return new LoadLimiter($resource, $isActive, $limitTime, $domain, $entityType, $entity, $method);
	}

	protected function readLoadLimiterSettings(): array
	{
		$configBitrix24 = new LoadLimiterConfigBitrix24();
		$config = $configBitrix24->isAvailable() ? $configBitrix24 : new LoadLimiterConfig();

		return [$config->getResource(), $config->isActive(), $config->getLimitTime(), $config->getDomain()];
	}

	protected function createQueryTimeLimiter(): QueryTimeLimiter
	{
		$config = new QueryTimeLimiterConfig();

		return new QueryTimeLimiter(Application::getInstance()->getConnectionPool(), $config->isActive(), $config->getSeconds());
	}
}
