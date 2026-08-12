<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class ServerAvailabilityService extends AbstractSupersetContext
{
	public function ping(): Result
	{
		$requestResult = $this->connector->get('/health');

		$result = new Result();
		$result->setData([
			'httpStatus' => $this->normalizePingStatus($requestResult->getHttpStatus()),
		]);

		return $result;
	}

	private function normalizePingStatus(int $httpStatus): int
	{
		if ($httpStatus === HttpStatus::DEACTIVATED_INSTANCE)
		{
			return HttpStatus::BAD_GATEWAY;
		}

		return $httpStatus;
	}
}
