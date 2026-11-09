<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api\Subscription;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;
use Bitrix\Superset\Internal\RequestResult;

final class SubscriptionService extends AbstractSupersetContext
{
	public function setExpiration(?int $timestamp, ?int $socketTimeout = null, ?int $streamTimeout = null): Main\Result
	{
		if (($timestamp ?? 0) <= 0)
		{
			return $this->createErrorResult('Parameter `timestamp` is required', null, HttpStatus::BAD_REQUEST);
		}

		$api = new Subscription($this->resolveConnector($socketTimeout, $streamTimeout));
		$requestResult = $api->setExpirationDate($timestamp);

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

	/**
	 * Timeouts asked for by the caller mean a connector of its own: the defaults live in the connector and are shared
	 * by every consumer of the module. Asking for none keeps the connector of the context - the one a test replaces.
	 *
	 * Built the same way as in `CacheService` and `SelfHostedLicenseService`.
	 */
	private function resolveConnector(?int $socketTimeout, ?int $streamTimeout): SupersetInstance
	{
		$options = [];
		if ($socketTimeout !== null)
		{
			$options['socketTimeout'] = $socketTimeout;
		}

		if ($streamTimeout !== null)
		{
			$options['streamTimeout'] = $streamTimeout;
		}

		return $options === [] ? $this->connector : new SupersetInstance($this->server, $options);
	}
}
