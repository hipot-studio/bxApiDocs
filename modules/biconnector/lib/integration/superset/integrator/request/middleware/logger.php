<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Error;

final class Logger extends Base
{
	private const ID = "LOGGER";
	private const REFUSAL_MESSAGE = 'Request refused';

	/**
	 * @param int[] $refusalStatuses Statuses of an expected refusal (a closed or missing object, a query
	 *        Superset could not run): journaled as INFO, since nothing is wrong with the service.
	 */
	public function __construct(
		private readonly IntegratorLogger $logger,
		private readonly array $refusalStatuses = [],
	)
	{}

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	public function afterRequest(IntegratorRequest $request, IntegratorResponse $response): IntegratorResponse
	{
		if ($response->hasErrors())
		{
			if (in_array($response->getStatus(), $this->refusalStatuses, true))
			{
				$this->logger->logMethodInfo(
					$request->getAction(),
					$response->getStatus(),
					self::REFUSAL_MESSAGE,
					['errors' => array_map(static fn(Error $e): string => $e->getMessage(), $response->getErrors())],
				);
			}
			else
			{
				$this->logger->logMethodErrors($request->getAction(), $response->getStatus(), $response->getErrors());
			}
		}

		return parent::afterRequest($request, $response);
	}
}
