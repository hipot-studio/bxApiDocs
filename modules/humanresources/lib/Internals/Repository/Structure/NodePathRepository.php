<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Internals\Repository\Structure;

use Bitrix\HumanResources\Model\NodePathTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;

final class NodePathRepository
{
	/**
	 * Returns absolute depth (distance from the structure root) for each given node id.
	 * Result is keyed by node id; missing ids mean the node has no path rows.
	 *
	 * No query-level cache: `b_hr_structure_node_path` is rewritten via raw SQL
	 * (e.g. NodePathTable::appendNode/moveWithSubtree/recalculate/deleteList), so the
	 * ORM Query cache would not be invalidated automatically. Hot callers are
	 * expected to cache at their own level (e.g. StructureBackwardAdapter).
	 *
	 * @param int[] $nodeIds
	 *
	 * @return array<int, int>
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getAbsoluteDepthMap(array $nodeIds): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

		$rows = NodePathTable::query()
			->setSelect(['CHILD_ID', 'MAX_DEPTH'])
			->registerRuntimeField(
				new ExpressionField('MAX_DEPTH', 'MAX(%s)', 'DEPTH'),
			)
			->whereIn('CHILD_ID', $nodeIds)
			->setGroup('CHILD_ID')
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[(int)$row['CHILD_ID']] = (int)$row['MAX_DEPTH'];
		}

		return $result;
	}

	/**
	 * Ancestors of several nodes at once, read off the materialized paths: one indexed read instead of a
	 * lookup per node. The row a node holds about itself is left out by the depth, the way the rest of the
	 * module reads the table ({@see \Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter}).
	 *
	 * No query-level cache here either, for the reason {@see self::getAbsoluteDepthMap()} names.
	 *
	 * @param list<int> $nodeIds
	 *
	 * @return array<int, list<int>> Ancestors of every node asked for, empty for a node of the top level.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findParentIdsByNodeIds(array $nodeIds): array
	{
		$nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
		if (!$nodeIds)
		{
			return [];
		}

		$parentIdsByNodeId = array_fill_keys($nodeIds, []);

		$rows = NodePathTable::query()
			->setSelect(['CHILD_ID', 'PARENT_ID'])
			->whereIn('CHILD_ID', $nodeIds)
			->where('DEPTH', '>', 0)
			->exec()
		;

		while ($row = $rows->fetch())
		{
			$parentIdsByNodeId[(int)$row['CHILD_ID']][] = (int)$row['PARENT_ID'];
		}

		return $parentIdsByNodeId;
	}
}
