<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Agent;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Config\ConfigContainer;
use Bitrix\BIConnector\Superset\Logger\SupersetInitializerLogger;
use Bitrix\Main\Error;

final class Diagnostics extends Base
{
	private const ID = 'DIAGNOSTICS';

	private const REFUSAL_CODES = [
		'USER_NOT_RESOLVED',
		'SERVER_AMBIGUOUS',
	];

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	/**
	 * @param Error[] $errors
	 */
	public static function isDiagnosedRefusal(array $errors): bool
	{
		return !empty(self::readRefusalCodes($errors));
	}

	public function afterRequest(IntegratorRequest $request, IntegratorResponse $response): IntegratorResponse
	{
		$refusalCodes = self::readRefusalCodes($response->getErrors());
		if (empty($refusalCodes))
		{
			return parent::afterRequest($request, $response);
		}

		self::journal($request, $response, $refusalCodes);
		Agent::notifyDataMismatch();

		return parent::afterRequest($request, $response);
	}

	private static function journal(
		IntegratorRequest $request,
		IntegratorResponse $response,
		array $refusalCodes,
	): void
	{
		$config = ConfigContainer::getConfigContainer();
		$user = $request->getUser();

		SupersetInitializerLogger::logErrors($response->getErrors(), [
			'message' => 'Superset service did not resolve the data of the portal',
			'action' => $request->getAction(),
			'codes' => $refusalCodes,
			'userId' => $user?->id,
			'clientId' => $user?->clientId,
			'portalIdFingerprint' => self::fingerprint($config->getPortalId()),
			'serverIds' => self::readServerIds($response),
			'serverGeneration' => $config->getServerGeneration(),
			'localDataGeneration' => $config->getLocalDataGeneration(),
		]);
	}

	private static function fingerprint(string $value): ?string
	{
		return $value === '' ? null : hash('sha256', $value);
	}

	/**
	 * @param Error[] $errors
	 * @return string[]
	 */
	private static function readRefusalCodes(array $errors): array
	{
		$codes = [];
		foreach ($errors as $error)
		{
			$code = (string)$error->getCode();
			if (in_array($code, self::REFUSAL_CODES, true))
			{
				$codes[] = $code;
			}
		}

		return array_values(array_unique($codes));
	}

	/**
	 * @return string[]
	 */
	private static function readServerIds(IntegratorResponse $response): array
	{
		$data = $response->getData();
		$serverIds = is_array($data) ? ($data['serverIds'] ?? null) : null;

		return is_array($serverIds) ? array_map('strval', $serverIds) : [];
	}
}
