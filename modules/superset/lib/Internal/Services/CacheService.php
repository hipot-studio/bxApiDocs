<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Cache;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class CacheService extends AbstractSupersetContext
{
	public function invalidate(): Main\Result
	{
		$api = new Cache($this->connector);
		$requestResult = $api->clearAll();

		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, 'Cache invalidation');
		}

		if (
			$requestResult->getHttpStatus() !== HttpStatus::OK
			&& $requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT
		)
		{
			return $this->createErrorResult(
				'Unexpected response when invalidating cache',
				$requestResult,
			);
		}

		$result = new Main\Result();
		$result->setData(['status' => 'ok']);

		return $result;
	}
}
