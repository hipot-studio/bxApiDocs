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

	public function __construct(
		private readonly IntegratorLogger $logger
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
			if (SupersetInitializer::isSupersetLoading())
			{
				return parent::afterRequest($request, $response);
			}

			// Instance is frozen for deferred deletion — don't overwrite PENDING_DELETE with LOAD,
			// otherwise the cleanup agent won't fire and initializeOrCheckSupersetStatus() will
			// keep retrying in an infinite loop (LOAD → request → 555 → LOAD → ...).
			if (SupersetInitializer::isSupersetPendingDelete())
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
			if ($response->getStatus() === IntegratorResponse::STATUS_UNKNOWN)
			{
				$errors = [new Error('Got unknown status code from proxy-service')];
			}
			else
			{
				$errors = [new Error('Got unsuccessful status from proxy-service')];
			}

			if ($response->hasErrors())
			{
				array_push($errors, ...$response->getErrors());
			}

			$this->logger->logMethodErrors($request->getAction(), $response->getStatus(), $errors);
			// Don't overwrite PENDING_DELETE with ERROR — a transient proxy error must not
			// prevent the cleanup agent from performing the actual deletion after timeout.
			if (!SupersetInitializer::isSupersetPendingDelete())
			{
				SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_ERROR);
			}
		}
		else if ($response->hasErrors())
		{
			$this->logger->logMethodErrors($request->getAction(), $response->getStatus(), $response->getErrors());
		}
		else
		{
			if (SupersetInitializer::isSupersetUnavailable())
			{
				SupersetInitializer::setSupersetStatus(SupersetInitializer::SUPERSET_STATUS_READY);
			}
		}

		return parent::afterRequest($request, $response);
	}

	private static function isServiceErrorCode(int $code): bool
	{
		return $code >= 500 && $code < 600;
	}
}
