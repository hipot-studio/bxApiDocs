<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Integration\Superset\SupersetStatusOptionContainer;
use Bitrix\Main\Error;

/**
 * Perform request while the instance exists.
 *
 * Unlike ReadyGate, a stale 'ERROR' or 'LOAD' status does not stop the request: the service answer decides
 * the outcome. Only the statuses that carry no instance to talk to stop the request before the network.
 *
 * @see ReadyGate
 */
final class InstanceExistsGate extends Base
{
	private const STATUSES_WITH_INSTANCE = [
		SupersetInitializer::SUPERSET_STATUS_READY,
		SupersetInitializer::SUPERSET_STATUS_ERROR,
		SupersetInitializer::SUPERSET_STATUS_LOAD,
	];

	public function __construct(
		private readonly SupersetStatusOptionContainer $statusOptionContainer,
		private readonly IntegratorLogger $logger
	) {}

	public static function getMiddlewareId(): string
	{
		return 'INSTANCE_EXISTS_GATE';
	}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		$status = $this->statusOptionContainer->get();
		if (in_array($status, self::STATUSES_WITH_INSTANCE, true))
		{
			return null;
		}

		$this->skipAfterMiddlewares();

		$action = $request->getAction();
		$errors = [new Error("For action '{$action}' an existing instance required, status is '{$status}'")];
		$this->logger->logErrors($errors);

		return new IntegratorResponse(
			IntegratorResponse::STATUS_INNER_ERROR,
			null,
			$errors
		);
	}
}
