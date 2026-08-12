<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Subscription;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\RequestResult;

final class SubscriptionService extends AbstractSupersetContext
{
	public function setExpiration(?int $timestamp): Main\Result
	{
		if (($timestamp ?? 0) <= 0)
		{
			return $this->createErrorResult('Parameter `timestamp` is required', null, HttpStatus::BAD_REQUEST);
		}

		$requestResult = $this->getSubscriptionApi()->setExpirationDate($timestamp);

		return $this->processMutationResult($requestResult, 'Setting subscription expiration');
	}

	public function syncRequire(?array $dashboards): Main\Result
	{
		if (!isset($dashboards))
		{
			return $this->createErrorResult(
				'Parameter `marketDashboardsIdList` is required',
				null,
				HttpStatus::BAD_REQUEST
			);
		}

		$requestResult = $this->getSubscriptionApi()->syncRequire($dashboards);

		return $this->processMutationResult($requestResult, 'Sync subscription required dashboards');
	}

	private function processMutationResult(RequestResult $requestResult, string $fallbackMessage): Main\Result
	{
		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, $fallbackMessage);
		}

		if ($requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT)
		{
			$errorMessage = trim($requestResult->getAnswer());
			if ($errorMessage === '')
			{
				$errorMessage = 'Unexpected Superset response status';
			}

			return $this->createErrorResult(
				$errorMessage,
				$requestResult,
				$requestResult->getHttpStatus()
			);
		}

		$result = new Main\Result();
		$result->setData([
			'status' => 'ok',
		]);

		return $result;
	}

	private function getSubscriptionApi(): Subscription
	{
		return new Subscription($this->connector);
	}
}
