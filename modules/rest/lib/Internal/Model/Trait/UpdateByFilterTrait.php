<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Model\Trait;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

trait UpdateByFilterTrait
{
	/**
	 * Mass update by an ORM filter, bypassing per-row reads and ORM events.
	 *
	 * @throws ArgumentException when there is nothing to set or the filter would match the whole table
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\DB\SqlQueryException
	 */
	public static function updateByFilter(array|ConditionTree $filter, array $fields): UpdateResult
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = static::getTableName();

		[$update] = $sqlHelper->prepareUpdate($tableName, $fields);
		if ($update === '')
		{
			throw new ArgumentException("There are no known columns to update ({$tableName}).", 'fields');
		}

		$where = Query::buildFilterSql($entity, $filter);
		if ($where === '')
		{
			throw new ArgumentException("Updating by empty filter is not allowed ({$tableName}).", 'filter');
		}

		$connection->queryExecute('UPDATE ' . $sqlHelper->quote($tableName) . " SET {$update} WHERE {$where}");

		$result = new UpdateResult();
		$result->setAffectedRowsCount($connection);

		if ($result->getAffectedRowsCount() > 0)
		{
			static::cleanCache();
		}

		return $result;
	}
}
