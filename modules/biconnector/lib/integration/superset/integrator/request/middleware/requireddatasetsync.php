<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\Main\Config\Option;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Services\ApacheSuperset;

final class RequiredDatasetSync extends Base
{
	private const ID = 'REQUIRED_DATASET_SYNC';
	private const INIT_REQUIRED_DATASET_TABLE_HASH = '~superset_init_required_dataset_table_hash';
	private const INIT_REQUIRED_DATASET_TIME = '~superset_init_required_dataset_last_attempt';
	private const INIT_INTERVAL = 3600; // 1 hour

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		if (!$this->canInitByTime())
		{
			return null;
		}

		$tableList = $this->getTableList();
		$currentTableHash = $this->calculateTableListHash($tableList);
		if ($currentTableHash === $this->getStoredTableListHash())
		{
			return null;
		}

		$initResponse = Integrator::getInstance()->initRequiredDataset($tableList);
		if ($initResponse->hasErrors())
		{
			return null;
		}

		$this->setStoredTableListHash($currentTableHash);

		return null;
	}

	private function canInitByTime(): bool
	{
		$lastAttempt = (int)Option::get('biconnector', self::INIT_REQUIRED_DATASET_TIME, 0);
		$now = time();

		$canInit = ($now - $lastAttempt) > self::INIT_INTERVAL;
		if ($canInit)
		{
			Option::set('biconnector', self::INIT_REQUIRED_DATASET_TIME, $now);
		}

		return $canInit;
	}

	private function getStoredTableListHash(): string
	{
		return (string)Option::get('biconnector', self::INIT_REQUIRED_DATASET_TABLE_HASH, '');
	}

	private function setStoredTableListHash(string $tableHash): void
	{
		Option::set('biconnector', self::INIT_REQUIRED_DATASET_TABLE_HASH, $tableHash);
	}

	private function getTableList(): array
	{
		$manager = Manager::getInstance();
		$service = new ApacheSuperset($manager);

		$tableList = $service->getTableList();
		if (empty($tableList))
		{
			return [];
		}

		$result = array_values(
			array_filter(
				array_unique(
					array_map(static fn($table) => (string)current($table), $tableList)
				),
				static fn($tableName) => $tableName !== ''
			)
		);
		sort($result);

		return $result;
	}

	private function calculateTableListHash(array $tableList): string
	{
		return md5(implode('|', $tableList));
	}
}
