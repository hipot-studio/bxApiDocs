<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class ServerAccessTokenService extends AbstractSupersetContext
{
	public function refresh(): Result
	{
		$refreshAccessTokenResult = $this->connector->refreshAccessToken();
		if (!$refreshAccessTokenResult->isSuccess())
		{
			return $refreshAccessTokenResult;
		}

		$accessToken = $refreshAccessTokenResult->getData()['access_token'] ?? null;
		if ($accessToken === null || $accessToken === '')
		{
			return $this->createErrorResult(
				'Failed to refresh superset access token',
				null,
				HttpStatus::BAD_GATEWAY,
			);
		}

		$result = new Result();
		$result->setData([
			'serverEntity' => $this->server,
			'token' => $accessToken,
		]);

		return $result;
	}
}
