<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

final class ServerAvailabilityService extends AbstractSupersetContext
{
	private const READINESS_SOCKET_TIMEOUT = 5;
	private const READINESS_STREAM_TIMEOUT = 10;

	private SupersetInstance $readinessConnector;

	public function __construct(
		Server $server,
		?SupersetInstance $connector = null,
		?SupersetInstance $readinessConnector = null,
	)
	{
		parent::__construct($server, $connector);

		// The readiness check is a single attempt: the start is repeated by the portal anyway.
		$this->readinessConnector = ($readinessConnector ?? new SupersetInstance($server, [
			'socketTimeout' => self::READINESS_SOCKET_TIMEOUT,
			'streamTimeout' => self::READINESS_STREAM_TIMEOUT,
		]))->disableGetRetry();
	}

	public function ping(): Result
	{
		$requestResult = $this->connector->get('/health');

		$result = new Result();
		$result->setData([
			'httpStatus' => $this->normalizePingStatus($requestResult->getHttpStatus()),
		]);

		return $result;
	}

	/**
	 * Unlike `/health`, the dashboard list reads the metadata database, so a started instance whose database
	 * is not reachable yet answers with a server error here. The status is returned as is, `555` and `521` included.
	 * A server error of the token refresh is returned instead of the client error of the final request.
	 *
	 * @param float|null $timeBudget seconds for the whole check, token refresh included; when it runs out,
	 * the check ends with `521`
	 */
	public function checkReadiness(?float $timeBudget = null): Result
	{
		if ($timeBudget !== null)
		{
			$this->readinessConnector->limitTotalTime($timeBudget);
		}

		$requestResult = (new Api\Dashboard($this->readinessConnector))->getDashboards(null, null, 1);

		$result = new Result();
		$result->setData([
			'httpStatus' => $this->resolveReadinessStatus($requestResult->getHttpStatus()),
		]);

		return $result;
	}

	private function resolveReadinessStatus(int $httpStatus): int
	{
		$tokenRefreshStatus = (int)$this->readinessConnector->getLastTokenRefreshHttpStatus();
		if (
			$httpStatus >= HttpStatus::BAD_REQUEST
			&& $httpStatus < HttpStatus::INTERNAL_SERVER_ERROR
			&& $tokenRefreshStatus >= HttpStatus::INTERNAL_SERVER_ERROR
		)
		{
			return $tokenRefreshStatus;
		}

		return $httpStatus;
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
