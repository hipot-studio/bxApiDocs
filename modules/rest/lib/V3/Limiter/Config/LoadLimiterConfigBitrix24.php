<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter\Config;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;

final class LoadLimiterConfigBitrix24 extends LoadLimiterConfig
{
	private const CONNECTION_NAME = 'cache.redis';
	private const FEATURE_VARIABLE = 'rest_load_limiter_seconds';

	public function isAvailable(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	public function isActive(): bool
	{
		return true;
	}

	public function getLimitTime(): int
	{
		return max(0, (int)Feature::getVariable(self::FEATURE_VARIABLE)) ?: self::DEFAULT_LIMIT_TIME;
	}

	public function getDomain(): string
	{
		return defined('BX24_HOST_NAME') ? BX24_HOST_NAME : self::DEFAULT_DOMAIN;
	}

	protected function getConnectionName(): string
	{
		return self::CONNECTION_NAME;
	}
}
