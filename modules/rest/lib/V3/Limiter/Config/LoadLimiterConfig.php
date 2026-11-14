<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter\Config;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class LoadLimiterConfig
{
	public const DEFAULT_LIMIT_TIME = 420;
	public const DEFAULT_DOMAIN = 'default';

	public function isActive(): bool
	{
		return Option::get('rest', 'load_limiter_active', 'N') === 'Y';
	}

	public function getResource(): ?object
	{
		$connectionName = $this->getConnectionName();
		if (!$this->isActive() || $connectionName === '')
		{
			return null;
		}

		$connection = Application::getInstance()->getConnectionPool()->getConnection($connectionName);
		$resource = $connection?->getResource();

		return $connection?->isConnected() ? $resource : null;
	}

	public function getLimitTime(): int
	{
		return (int)Option::get('rest', 'load_limiter_second_limit', self::DEFAULT_LIMIT_TIME);
	}

	public function getDomain(): string
	{
		return self::DEFAULT_DOMAIN;
	}

	protected function getConnectionName(): string
	{
		return Option::get('rest', 'load_limiter_connection_name', '');
	}
}
