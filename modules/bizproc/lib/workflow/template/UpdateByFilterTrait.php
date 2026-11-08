<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Main\ORM\Query\Query;

/**
 * Compare-and-set over the raw table: the row is written only while the filter still matches, so a
 * concurrent writer cannot be overwritten unnoticed.
 *
 * The counterpart of {@see \Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait} for the write side.
 */
trait UpdateByFilterTrait
{
	/**
	 * The builder works by table name and knows nothing of the field map, so a serialized field has to be
	 * passed in its stored form.
	 *
	 * @return int number of rows actually written
	 */
	public static function updateByFilter(array $filter, array $fields): int
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		[$update, $binds] = $connection->getSqlHelper()->prepareUpdate(static::getTableName(), $fields);
		$where = Query::buildFilterSql($entity, $filter);

		if ($where === '' || $update === '')
		{
			return 0;
		}

		$connection->queryExecute('UPDATE ' . static::getTableName() . " SET {$update} WHERE {$where}", $binds);

		return $connection->getAffectedRowsCount();
	}
}
