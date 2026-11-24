<?php

namespace Bitrix\BIConnector\Superset\Operation;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\Web\Json;

final class OperationIntentService
{
	public const LIFETIME_SECONDS = 900;

	public const FIELD_OPERATION_ID = 'operationId';
	public const FIELD_PARAMS_HASH = 'paramsHash';

	public static function calculateParamsHash(array $params): string
	{
		return hash('sha256', Json::encode(self::sortRecursively($params)));
	}

	public function create(string $operation, ?int $serverId, string $generation, array $params): Result
	{
		$result = new Result();

		$operationId = UuidGenerator::generateV4();
		$paramsHash = self::calculateParamsHash($params);

		$addResult = OperationIntentTable::add([
			'OPERATION_ID' => $operationId,
			'OPERATION' => $operation,
			'SERVER_ID' => $serverId,
			'GENERATION' => $generation,
			'PARAMS_HASH' => $paramsHash,
			'DATE_CREATE' => new DateTime(),
			'DATE_EXPIRE' => (new DateTime())->add(self::LIFETIME_SECONDS . ' seconds'),
		]);
		if (!$addResult->isSuccess())
		{
			return $result->addErrors($addResult->getErrors());
		}

		return $result->setData([
			self::FIELD_OPERATION_ID => $operationId,
			self::FIELD_PARAMS_HASH => $paramsHash,
		]);
	}

	public function findConfirmed(
		string $operationId,
		string $operation,
		string $generation,
		string $paramsHash,
	): ?array
	{
		if ($operationId === '')
		{
			return null;
		}

		return OperationIntentTable::getRow([
			'select' => ['OPERATION_ID', 'OPERATION', 'SERVER_ID', 'GENERATION', 'PARAMS_HASH'],
			'filter' => [
				'=OPERATION_ID' => $operationId,
				'=OPERATION' => $operation,
				'=GENERATION' => $generation,
				'=PARAMS_HASH' => $paramsHash,
				'>DATE_EXPIRE' => new DateTime(),
			],
		]);
	}

	public function drop(string $operationId): void
	{
		if ($operationId === '')
		{
			return;
		}

		OperationIntentTable::deleteByFilter(['=OPERATION_ID' => $operationId]);
	}

	public function cleanUpExpired(): void
	{
		OperationIntentTable::deleteByFilter(['<=DATE_EXPIRE' => new DateTime()]);
	}

	private static function sortRecursively(array $params): array
	{
		ksort($params);

		foreach ($params as $key => $value)
		{
			if (is_array($value))
			{
				$params[$key] = self::sortRecursively($value);
			}
		}

		return $params;
	}
}
