<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

final class Timezone
{
	private const TIMEZONE_API_LINK = '/api/v1/bitrix/timezone/';
	private const NAME_PARAMETER = 'name';

	public function __construct(
		private readonly SupersetInstance $connector,
	)
	{
	}

	public function setTimezone(string $timezone): RequestResult
	{
		return $this->connector->put(self::TIMEZONE_API_LINK, [
			self::NAME_PARAMETER => $timezone,
		]);
	}
}
