<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Model\CheckListRevisionTable;

class CheckListRevisionRepository implements CheckListRevisionRepositoryInterface
{
	public function getRevision(int $entityId, Type $type): int
	{
		$row = CheckListRevisionTable::query()
			->setSelect(['REVISION'])
			->where('ENTITY_ID', $entityId)
			->where('ENTITY_TYPE', $type->value)
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['REVISION'] : 0;
	}

	public function bumpRevision(int $entityId, Type $type): int
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = CheckListRevisionTable::getTableName();
		$entityType = $helper->forSql($type->value);

		$updateSql = "UPDATE {$table} SET REVISION = REVISION + 1"
			. " WHERE ENTITY_ID = {$entityId} AND ENTITY_TYPE = '{$entityType}'";

		$connection->queryExecute($updateSql);

		if ($connection->getAffectedRowsCount() <= 0)
		{
			try
			{
				$connection->queryExecute(
					"INSERT INTO {$table} (ENTITY_ID, ENTITY_TYPE, REVISION)"
					. " VALUES ({$entityId}, '{$entityType}', 1)"
				);
			}
			catch (SqlQueryException)
			{
				$connection->queryExecute($updateSql);
			}
		}

		return $this->getRevision($entityId, $type);
	}

	public function tryReserveRevision(int $entityId, Type $type, int $baseRevision): ?int
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = CheckListRevisionTable::getTableName();
		$entityType = $helper->forSql($type->value);

		if ($baseRevision > 0)
		{
			$connection->queryExecute(
				"UPDATE {$table} SET REVISION = REVISION + 1"
				. " WHERE ENTITY_ID = {$entityId} AND ENTITY_TYPE = '{$entityType}' AND REVISION = {$baseRevision}"
			);

			return $connection->getAffectedRowsCount() === 1 ? $baseRevision + 1 : null;
		}

		try
		{
			$connection->queryExecute(
				"INSERT INTO {$table} (ENTITY_ID, ENTITY_TYPE, REVISION) VALUES ({$entityId}, '{$entityType}', 1)"
			);

			return 1;
		}
		catch (SqlQueryException)
		{
			return null;
		}
	}

	public function deleteRevision(int $entityId, Type $type): void
	{
		CheckListRevisionTable::delete([
			'ENTITY_ID' => $entityId,
			'ENTITY_TYPE' => $type->value,
		]);
	}
}
