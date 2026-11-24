<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Logger\IntegratorLogger;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use \Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class Registrar extends Base
{
	private const ID = 'REGISTRAR';

	public function __construct(
		private readonly \Bitrix\BIConnector\Integration\Superset\Registrar $registrar,
		private readonly IntegratorLogger $logger
	) {}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		if ($this->registrar->isComplete())
		{
			return null;
		}

		$this->logger->logInfo('portal make register action', ['trigger_request' => $request->getAction()]);
		$result = $this->registrar->register();
		if ($result->isSuccess())
		{
			return null;
		}

		$this->logger->logErrors([
			new Error('cannot register portal on supersetproxy while make method ' . $request->getAction()),
			...$result->getErrors(),
		]);

		return self::buildFailureResponse($result);
	}

	public function afterRequest(IntegratorRequest $request, IntegratorResponse $response): IntegratorResponse
	{
		if ($response->getStatus() === IntegratorResponse::STATUS_REGISTER_REQUIRED)
		{
			if ($this->registrar->isComplete())
			{
				$errors = [new Error("Unexpected proxy response: 'registration required' received for already registered portal"), ...$response->getErrors()];
				$this->logger->logErrors($errors);

				return new IntegratorResponse(
					IntegratorResponse::STATUS_UNKNOWN,
					null,
					[new Error("Unexpected proxy response: 'registration required' received for already registered portal", IntegratorResponse::STATUS_UNKNOWN)]
				);
			}

			$this->logger->logInfo("Portal got 'register required' response. Clear registrar info");
			$response->setStatus(IntegratorResponse::STATUS_FROZEN);
			$this->registrar->clear(__CLASS__ . '::' . __FUNCTION__);
		}

		return $response;
	}

	private static function buildFailureResponse(Result $result): IntegratorResponse
	{
		$errors = $result->getErrors();
		$status = (int)($result->getData()['STATUS_CODE'] ?? 0);

		if ($status === IntegratorResponse::STATUS_LIMIT_EXCEEDED)
		{
			return new IntegratorResponse(IntegratorResponse::STATUS_LIMIT_EXCEEDED, null, $errors);
		}

		if (Diagnostics::isDiagnosedRefusal($errors))
		{
			return new IntegratorResponse(IntegratorResponse::STATUS_INNER_ERROR, null, $errors);
		}

		if (self::hasMachineCode($errors))
		{
			return new IntegratorResponse(IntegratorResponse::STATUS_UNKNOWN, null, $errors);
		}

		return new IntegratorResponse(
			IntegratorResponse::STATUS_UNKNOWN,
			null,
			[new Error('portal registration is incomplete', IntegratorResponse::STATUS_UNKNOWN)]
		);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function hasMachineCode(array $errors): bool
	{
		foreach ($errors as $error)
		{
			$code = $error->getCode();
			if (is_string($code) && $code !== '' && !is_numeric($code))
			{
				return true;
			}
		}

		return false;
	}

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}
}