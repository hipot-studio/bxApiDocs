<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class TimezoneService extends AbstractSupersetContext
{
	public function setTimezone(string $timezone): Main\Result
	{
		$requestResult = (new Api\Timezone($this->connector))->setTimezone($timezone);
		if (!in_array($requestResult->getHttpStatus(), [HttpStatus::OK, HttpStatus::NO_CONTENT], true))
		{
			return $this->createRequestErrorResult($requestResult, 'Setting timezone for Superset instance');
		}

		return new Main\Result();
	}
}
