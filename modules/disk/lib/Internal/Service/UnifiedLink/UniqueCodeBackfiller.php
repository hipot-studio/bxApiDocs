<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink;

use Bitrix\Disk\Internals\UniqueCode;
use Bitrix\Main\Application;
use Throwable;

class UniqueCodeBackfiller
{
	private array $processedObjectIds = [];

	public function backfillUniqueCode(int $objectId): bool
	{
		if (!isset($this->processedObjectIds[$objectId]))
		{
			$connection = Application::getConnection();
			$uniqueCode = (new UniqueCode())->generate();
			$sqlHelper = $connection->getSqlHelper();

			try
			{
				$connection->queryExecute(
					"UPDATE b_disk_object
						SET UNIQUE_CODE = '" . $sqlHelper->forSql($uniqueCode) . "'
						WHERE ID = {$objectId} AND (UNIQUE_CODE IS NULL OR UNIQUE_CODE = '')"
				);

				if ($connection->getAffectedRowsCount() > 0)
				{
					$this->processedObjectIds[$objectId] = $uniqueCode;

					return true;
				}

				$actualUniqueCode = (string)$connection->queryScalar(
					"SELECT UNIQUE_CODE FROM b_disk_object WHERE ID = {$objectId}"
				);

				$this->processedObjectIds[$objectId] = $actualUniqueCode;

				return $actualUniqueCode !== '';
			}
			catch (Throwable)
			{
				$this->processedObjectIds[$objectId] = '';

				return false;
			}
		}

		return !empty($this->getBackfilledUniqueCode($objectId));
	}

	public function getBackfilledUniqueCode(int $objectId): ?string
	{
		return $this->processedObjectIds[$objectId] ?? null;
	}
}
