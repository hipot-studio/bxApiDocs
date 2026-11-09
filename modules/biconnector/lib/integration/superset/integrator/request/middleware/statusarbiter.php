<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Error;

final class StatusArbiter extends Base
{
	private const ID = 'STATUS_ARBITER';

	/**
	 * @param bool $arbitrateServiceFailure Whether a service failure may move the instance to ERROR.
	 *        A request made behind an unattended schedule passes false: the recovery series out of
	 *        ERROR is opened by a user visit only, so on such a path a single failure would become
	 *        a permanent one. Frozen answers and the return to READY are arbitrated either way.
	 */
	public function __construct(
		private readonly IntegratorLogger $logger,
		private readonly bool $arbitrateServiceFailure = true,
	)
	{}

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	public function afterRequest(IntegratorRequest $request, IntegratorResponse $response): IntegratorResponse
	{
		if ($response->getStatus() === IntegratorResponse::STATUS_FROZEN)
		{
			// Both checks read one status: writing the update mark below resets the module option cache,
			// and a status re-read after it could see a concurrent change and let LOAD overwrite it.
			$isLoading = SupersetInitializer::isSupersetLoading();

			// Instance is frozen for deferred deletion — don't overwrite PENDING_DELETE with LOAD,
			// otherwise the cleanup agent won't fire and initializeOrCheckSupersetStatus() will
			// keep retrying in an infinite loop (LOAD → request → 555 → LOAD → ...).
			if (!$isLoading && SupersetInitializer::isSupersetPendingDelete())
			{
				return parent::afterRequest($request, $response);
			}

			// The mark is refreshed by every frozen answer, including the ones received while already loading:
			// an update may start on an instance that is unfreezing, and an answer without the signal ends it.
			SupersetInitializer::markSupersetUpdating($response->isInstanceUpdating());

			if ($isLoading)
			{
				return parent::afterRequest($request, $response);
			}

			$this->logger->logMethodInfo($request->getAction(), $response->getStatus(), 'superset is load');
			SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_LOAD);

			return parent::afterRequest($request, $response);
		}

		if (SupersetInitializer::isSupersetLoading())
		{
			return parent::afterRequest($request, $response);
		}

		if (self::isServiceErrorCode($response->getStatus()))
		{
			// A response carrying errors is already journaled by the Logger middleware,
			// and only a refusal silent about its reason needs a record of its own.
			if (!$response->hasErrors())
			{
				$this->logger->logMethodErrors(
					$request->getAction(),
					$response->getStatus(),
					[new Error(self::describeServiceFailure($response->getStatus()))],
				);
			}

			// Don't overwrite PENDING_DELETE with ERROR — a transient proxy error must not
			// prevent the cleanup agent from performing the actual deletion after timeout.
			if ($this->arbitrateServiceFailure && !SupersetInitializer::isSupersetPendingDelete())
			{
				SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_ERROR);
			}
		}
		else if (!$response->hasErrors() && SupersetInitializer::isSupersetUnavailable())
		{
			SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_READY);
		}

		return parent::afterRequest($request, $response);
	}

	private static function describeServiceFailure(int $status): string
	{
		return $status === IntegratorResponse::STATUS_UNKNOWN
			? 'Got unknown status code from proxy-service'
			: 'Got unsuccessful status from proxy-service'
		;
	}

	private static function isServiceErrorCode(int $code): bool
	{
		return $code >= 500 && $code < 600;
	}
}
