<?php

namespace Bitrix\Superset\Internal\Api;

use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\RequestResult;

final class JwtStatus
{
	private const JWT_STATUS_API_LINK = '/api/v1/bitrix/jwt/status/';

	public function __construct(
		private readonly SupersetInstance $connector,
	)
	{
	}

	public function getStatus(): RequestResult
	{
		return $this->connector->get(self::JWT_STATUS_API_LINK);
	}
}
