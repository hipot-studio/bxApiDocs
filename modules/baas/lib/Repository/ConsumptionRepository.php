<?php

declare(strict_types=1);

namespace Bitrix\Baas\Repository;

use Bitrix\Baas;
use Bitrix\Baas\Model\PurchasedPackageTable;
use Bitrix\Main;
use Bitrix\Baas\Model\ConsumptionLogTable;
use Bitrix\Baas\Model\ServiceInPurchasedPackageTable;
use Bitrix\Baas\Model\ServiceTable;

class ConsumptionRepository implements ConsumptionRepositoryInterface
{
	protected const ROWS_COUNT_PER_ONE_SYNC = 100;
	protected bool $enabled;

	use Baas\Internal\Trait\SingletonConstructor;

	protected function __construct()
	{
	}

	public function isEnabled(): bool
	{
		if (!isset($this->enabled))
		{
			$this->enabled = Main\Application::getConnection()->isTableExists(ConsumptionLogTable::getTableName());
		}

		return $this->enabled;
	}

	public function resetLogForMigration(): void
	{
		Baas\Model\ConsumptionLogTable::updateBatch(
			[
				'MIGRATION_DATE' => null,
				'MIGRATION_ID' => null,
				'MIGRATED' => 'N',
			],
			[
				'!=MIGRATED' => 'Ya!',
			],
		);
	}

	public function collectLogForMigration(string $marker): array
	{
		$updateFilter = [
			'MIGRATION_ID' => null,
			'!=MIGRATED' => 'Y',
		];

		if ($highestInThisLog = Baas\Model\ConsumptionLogTable::query()
			->setSelect(['ID'])
			->where('MIGRATION_ID', null)
			->whereNot('MIGRATED', 'Y')
			->setLimit(1)
			->setOffset(self::ROWS_COUNT_PER_ONE_SYNC)
			->setOrder(['ID' => 'ASC'])
			->fetch()
		)
		{
			$updateFilter['<ID'] = $highestInThisLog['ID'];
		}

		Baas\Model\ConsumptionLogTable::updateBatch(
			['MIGRATION_ID' => $marker],
			$updateFilter,
		);

		$consumptionResult = Baas\Model\ConsumptionLogTable::query()
			->setSelect(['ID', 'CONSUMPTION_ID', 'SERVICE_CODE', 'PURCHASED_PACKAGE_CODE', 'VALUE', 'TIMESTAMP_USE'])
			->where('MIGRATION_ID', $marker)
			->setOrder(['ID' => 'ASC'])
			->exec()
		;

		$collection = [];
		while ($entry = $consumptionResult->fetchObject())
		{
			$collection[] = [
				'ID' => $entry->getId(),
				'CONSUMPTION_ID' => $entry->getConsumptionId(),
				'SERVICE_CODE' => $entry->getServiceCode(),
				'PURCHASED_PACKAGE_CODE' => $entry->getPurchasedPackageCode(),
				'DIRECTION' => $entry->getValue() > 0 ? 1 : 0,
				'VALUE' => abs($entry->getValue()),
				'TIMESTAMP_USE' => $entry->getTimestampUse()->getTimestamp(),
			];
		}

		return $collection;
	}

	public function crossOutByMigrationMarker(string $marker): void
	{
		$result = Baas\Model\ConsumptionLogTable::updateBatch(
			[
				'MIGRATION_DATE' => new Main\Type\DateTime(),
				'MIGRATED' => 'Y',
			],
			[
				'=MIGRATION_ID' => $marker,
			],
		);

		Baas\Internal\Diag\Logger::getInstance()->info('crossOutByMigrationMarker', [
			'marker' => $marker,
			$result->getAffectedRowsCount(),
		]);
	}

	public function resetMigrationMarker(string $marker): void
	{
		Baas\Model\ConsumptionLogTable::updateBatch(
			[
				'MIGRATION_DATE' => null,
				'MIGRATION_ID' => null,
				'MIGRATED' => 'N',
			],
			[
				'=MIGRATION_ID' => $marker,
			],
		);
	}

	public function hasLocalConsumptions(): bool
	{
		Baas\Model\ConsumptionLogTable::updateBatch(
			[
				'MIGRATION_DATE' => null,
				'MIGRATION_ID' => null,
			],
			[
				'!=MIGRATED' => 'Y',
				'<MIGRATION_DATE' => (new Main\Type\DateTime())->add('-2 minutes'),
			],
		);

		return Baas\Model\ConsumptionLogTable::query()
			->setSelect(['ID'])
			->whereNot('MIGRATED', 'Y')
			->setLimit(1)
			->fetchObject()
			!== null
		;
	}

	public function consume(string $serviceCode, string $consumptionId, int $units): Result\BalanceResult
	{
		$connection = Main\Application::getConnection();
		$connection->startTransaction();

		$unitsToDeleteFromPurchase = $units;

		$purchasedServiceCollection = ServiceInPurchasedPackageTable::query()
			->setSelect(['*', 'SERVICE.RENEWABLE', 'PURCHASED_PACKAGE.PURCHASE_CODE'])
			->where('SERVICE_CODE', $serviceCode)
			->where('CURRENT_VALUE', '>', 0)
			->where('PURCHASED_PACKAGE.ACTUAL', 'Y')
			->setOrder([
				'PURCHASED_PACKAGE.START_DATE' => 'ASC',
				'PURCHASED_PACKAGE.ID' => 'ASC',
				'ID' => 'ASC'])
			->exec()
			->fetchCollection()
		;

		foreach ($purchasedServiceCollection as $purchasedService)
		{
			$possibleToDecrement = $purchasedService->getCurrentValue();
			$decrement = min($unitsToDeleteFromPurchase, $possibleToDecrement);
			$unitsToDeleteFromPurchase -= $decrement;
			ServiceInPurchasedPackageTable::update([
				'PURCHASED_PACKAGE_CODE' => $purchasedService->getPurchasedPackageCode(),
				'SERVICE_CODE' => $purchasedService->getServiceCode(),
			], [
				'CURRENT_VALUE' => new Main\DB\SqlExpression('?# - ?i', 'CURRENT_VALUE', $decrement),
			]);

			$this->logConsumption(
				$serviceCode,
				0 - $decrement,
				$purchasedService->getPurchasedPackageCode(),
				$consumptionId,
			);

			if ($unitsToDeleteFromPurchase <= 0)
			{
				break;
			}
		}

		if ($unitsToDeleteFromPurchase > 0)
		{
			$this->logConsumption(
				$serviceCode,
				0 - $unitsToDeleteFromPurchase,
				null,
				$consumptionId,
			);
		}

		$currentValue = $this->refreshCurrentValues($serviceCode);

		$connection->commitTransaction();

		return new Result\BalanceResult(
			$currentValue,
		);
	}

	/**
	 * @param string $serviceCode
	 * @param string $consumptionId
	 * @return Main\Result
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 * @throws Main\ObjectPropertyException
	 * @throws Main\SystemException
	 */
	public function refund(string $serviceCode, string $consumptionId): Result\BalanceResult
	{
		$connection = Main\Application::getConnection();
		$connection->startTransaction();

		$consumptionLogResult = ConsumptionLogTable::query()
			->setSelect([
				'PURCHASED_PACKAGE_CODE',
				new Main\ORM\Fields\ExpressionField('CONSUMPTION', 'SUM(%s)', 'VALUE'),
			])
			->where('SERVICE_CODE', $serviceCode)
			->where('CONSUMPTION_ID', $consumptionId)
			->where('CONSUMPTION', '<', 0)
			->setGroup(['PURCHASED_PACKAGE_CODE'])
			->exec()
		;

		$unitsToIncrementInService = 0;
		foreach ($consumptionLogResult as $consumptionLog)
		{
			$units = abs($consumptionLog['CONSUMPTION']);
			$updateResult = ServiceInPurchasedPackageTable::update([
				'SERVICE_CODE' => $serviceCode,
				'PURCHASED_PACKAGE_CODE' => $consumptionLog['PURCHASED_PACKAGE_CODE'],
			], [
				'CURRENT_VALUE' => new Main\DB\SqlExpression('?# + ?i', 'CURRENT_VALUE', $units),
			]);

			$this->logConsumption(
				$serviceCode,
				$units,
				$consumptionLog['PURCHASED_PACKAGE_CODE'],
				$consumptionId,
			);

			if ($updateResult->getAffectedRowsCount() > 0)
			{
				$unitsToIncrementInService += $units;
			}
		}

		$serviceValue = 0;
		if ($unitsToIncrementInService > 0)
		{
			$serviceValue = $this->refreshCurrentValues($serviceCode);
		}

		$connection->commitTransaction();

		return new Result\BalanceResult(
			$serviceValue,
		);
	}

	private function logConsumption(
		string $serviceCode,
		int $units,
		?string $purchasedPackageCode = '',
		string $consumptionId = '',
	): void
	{
		ConsumptionLogTable::add([
			'SERVICE_CODE' => $serviceCode,
			'PURCHASED_PACKAGE_CODE' => $purchasedPackageCode ?? '',
			'CONSUMPTION_ID' => $consumptionId,
			'VALUE' => $units,
		]);
	}

	private function refreshCurrentValues(string $serviceCode): int
	{
		$data = ServiceInPurchasedPackageTable::query()
			->setSelect([
				'CURRENTV' => new Main\ORM\Fields\ExpressionField('CURRENTV', 'SUM(%s)', 'CURRENT_VALUE'),
				'PURCHASED_PACKAGE_CODE',
			])
			->where('SERVICE_CODE', $serviceCode)
			->where('CURRENT_VALUE', '>', 0)
			->where('PURCHASED_PACKAGE.ACTUAL', 'Y')
			->setGroup(['PURCHASED_PACKAGE_CODE'])
			->exec()
			->fetchAll()
		;
		$currentValue = array_sum(array_column($data, 'CURRENTV'));

		ServiceTable::update(['CODE' => $serviceCode], ['CURRENT_VALUE' => $currentValue]);

		$purchasedPackageCodes = array_column($data, 'PURCHASED_PACKAGE_CODE');

		$data = ServiceInPurchasedPackageTable::query()
			->setSelect([
				'CURRENTV' => new Main\ORM\Fields\ExpressionField('CURRENTV', 'SUM(%s)', 'CURRENT_VALUE'),
				'PURCHASED_PACKAGE_CODE',
			])
			->where('PURCHASED_PACKAGE.ACTIVE', 'Y')
			->where('SERVICE.RENEWABLE', 'N')
			->whereNotIn('PURCHASED_PACKAGE_CODE', $purchasedPackageCodes)
			->setGroup(['PURCHASED_PACKAGE_CODE'])
			->exec()
			->fetchAll()
		;
		$needToDeactivate = array_filter($data, static function($item) { return $item['CURRENTV'] <= 0; });

		if ($needToDeactivate)
		{
			$needToDeactivate = array_column($needToDeactivate, 'PURCHASED_PACKAGE_CODE');
			PurchasedPackageTable::updateBatch(
				['ACTIVE' => 'N'],
				[
					'@CODE' => $needToDeactivate,
				],
			);
		}

		return $currentValue;
	}
}
