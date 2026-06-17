<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Stepper;

use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageItem;
use Bitrix\Bizproc\Internal\Model\StorageRecordDataTable;
use Bitrix\Bizproc\Internal\Model\StorageRecordFieldTable;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Entity\StorageItem\StorageEavMigrationPhase;
use Bitrix\Main;

class StorageItemMigrationStepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';

	private const STEP_ROWS_LIMIT = 50;

	public function execute(array &$option)
	{
		if (!isset($option['count']))
		{
			$option['count'] = StorageRecordDataTable::getCount();
			$option['steps'] = 0;
			$option['lastId'] = 0;
		}

		if ($option['count'] <= 0)
		{
			StorageEavMigrationPhase::DualWriteEavRead->save();

			return self::FINISH_EXECUTION;
		}

		$lastId = (int)$option['lastId'];

		$rows = StorageRecordDataTable::getList([
			'filter' => ['>ID' => $lastId],
			'order' => ['ID' => 'ASC'],
			'limit' => self::STEP_ROWS_LIMIT,
		])->fetchAll();

		if (empty($rows))
		{
			StorageEavMigrationPhase::DualWriteEavRead->save();

			return self::FINISH_EXECUTION;
		}

		$recordIds = array_column($rows, 'ID');
		$migratedIds = [];

		$dbRes = StorageRecordFieldTable::getList([
			'select' => ['RECORD_ID'],
			'filter' => ['@RECORD_ID' => $recordIds],
		]);

		while ($item = $dbRes->fetch())
		{
			$migratedIds[$item['RECORD_ID']] = true;
		}

		$fieldValueRepository = Container::getStorageFieldValueRepository();

		foreach ($rows as $row)
		{
			if (isset($migratedIds[$row['ID']]))
			{
				$option['lastId'] = $row['ID'];
				$option['steps']++;

				continue;
			}

			$this->migrateValueFields($row, $fieldValueRepository);

			$option['lastId'] = $row['ID'];
			$option['steps']++;
		}

		if ($option['steps'] >= $option['count'])
		{
			StorageEavMigrationPhase::DualWriteEavRead->save();

			return self::FINISH_EXECUTION;
		}

		return self::CONTINUE_EXECUTION;
	}

	private function migrateValueFields(array $row, $fieldValueRepository): void
	{
		$storageId = (int)$row['STORAGE_ID'];
		$recordId = (int)$row['ID'];

		$values = $row['VALUE'] ?? [];
		if (!is_array($values) || empty($values))
		{
			return;
		}

		$storageItem = new StorageItem();
		$storageItem
			->setId($recordId)
			->setStorageId($storageId)
		;

		foreach ($values as $code => $value)
		{
			$storageItem->setValueField($code, $value);
		}

		$connection = Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			$fieldValueRepository->add($storageItem);
			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			Main\Application::getInstance()->getExceptionHandler()->writeToLog($e);
		}
	}
}
