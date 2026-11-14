<?php

namespace Bitrix\Disk\Internals\Grid;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Main\Application;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Finds the page of the grid an object stands on.
 * The objects visible to the user and standing before the object in the order of the grid are counted
 * by one query, so the cost does not grow with the position of the object in a large folder.
 * @internal
 */
final class ObjectPageLocator
{
	/**
	 * @param int $objectId
	 * @param array $order Order of the grid, as FolderListOptions::getOrderForOrm() returns it.
	 * @param array $filter Filter of the listing the object stands in.
	 * @param SecurityContext $securityContext
	 * @param int $pageSize
	 * @return int Number of the page, starting from 1.
	 */
	public function getPage(int $objectId, array $order, array $filter, SecurityContext $securityContext, int $pageSize): int
	{
		// the grid leaves the order of equal rows to the database, the key of the object makes the position certain
		$order['ID'] ??= 'ASC';

		$values = ObjectTable::getRow([
			'select' => array_keys($order),
			'filter' => ['=ID' => $objectId],
		]);
		if (!$values)
		{
			return 1;
		}

		$tree = Query::filter();
		foreach ($filter as $field => $value)
		{
			$tree->where($field, $value);
		}
		$tree->where($this->buildStandsBefore($order, $values));

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			$securityContext,
			[
				'select' => ['CNT'],
				'filter' => $tree,
				'runtime' => [new ExpressionField('CNT', 'COUNT(1)')],
			],
			['ID', 'CREATED_BY'],
		);

		$row = ObjectTable::getList($parameters)->fetch();

		return intdiv((int)($row['CNT'] ?? 0), max($pageSize, 1)) + 1;
	}

	/**
	 * Row stands before the object when it equals the object in the first fields of the order and
	 * goes earlier in the next one.
	 */
	private function buildStandsBefore(array $order, array $values): ConditionTree
	{
		$standsBefore = Query::filter()->logic('or');
		$equalFields = [];
		foreach ($order as $field => $direction)
		{
			$goesEarlier = $this->buildGoesEarlier($field, strtoupper($direction) === 'DESC', $values[$field]);
			if ($goesEarlier)
			{
				$condition = Query::filter();
				foreach ($equalFields as $equalField)
				{
					$condition->where($equalField);
				}
				$standsBefore->where($condition->where($goesEarlier));
			}

			$equalFields[] = $values[$field] === null
				? Query::filter()->whereNull($field)
				: Query::filter()->where($field, $values[$field]);
		}

		if (!$standsBefore->hasConditions())
		{
			$standsBefore->whereExpr('1 = 0', []);
		}

		return $standsBefore;
	}

	private function buildGoesEarlier(string $field, bool $descending, mixed $value): ?ConditionTree
	{
		$nullsFirst = $this->isNullFirst($descending);

		if ($value === null)
		{
			return $nullsFirst ? null : Query::filter()->whereNotNull($field);
		}

		$goesEarlier = Query::filter()->logic('or')->where($field, $descending ? '>' : '<', $value);
		if ($nullsFirst)
		{
			$goesEarlier->whereNull($field);
		}

		return $goesEarlier;
	}

	private function isNullFirst(bool $descending): bool
	{
		// MySQL puts NULL first in ascending order, PostgreSQL puts it last
		$nullIsSmallest = !(Application::getConnection() instanceof PgsqlConnection);

		return $nullIsSmallest !== $descending;
	}
}
