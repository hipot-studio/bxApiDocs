<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Limiter\Config;

use Bitrix\Main\Config\Option;

final class QueryTimeLimiterConfig
{
	public const OPTION_ACTIVE = 'sql_query_time_limit_active';
	public const OPTION_SECONDS = 'sql_query_time_limit';
	public const DEFAULT_SECONDS = 30;

	public function isActive(): bool
	{
		return Option::get('rest', self::OPTION_ACTIVE, 'N') === 'Y';
	}

	public function getSeconds(): int
	{
		return max(0, (int)Option::get('rest', self::OPTION_SECONDS, self::DEFAULT_SECONDS));
	}
}
