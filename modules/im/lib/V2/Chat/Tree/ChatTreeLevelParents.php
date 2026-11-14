<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Common\Normalize;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Resolves the parents of the next tree level from the chat tree alone.
 */
class ChatTreeLevelParents
{
	// stays below the MySQL eq_range_index_dive_limit default of 200 to keep the range plan for IN()
	public const IN_CHUNK_SIZE = 199;

	private const CHILD_ALIAS = 'TREE_CHILD';

	/**
	 * Returns the children of the given level chats that have children of their own.
	 *
	 * @param int[] $levelParentIds
	 * @param TypeCondition|null $typeCondition checked on the returned chats
	 * @return int[]
	 */
	public function findNextLevelParentIds(array $levelParentIds, ?TypeCondition $typeCondition = null): array
	{
		$levelParentIds = Normalize::uniquePositiveIntegers($levelParentIds);

		$nextLevelParentIds = [];
		foreach (array_chunk($levelParentIds, self::IN_CHUNK_SIZE) as $chunk)
		{
			array_push($nextLevelParentIds, ...$this->fetchNextLevelParentIds($chunk, $typeCondition));
		}

		return Normalize::uniquePositiveIntegers($nextLevelParentIds);
	}

	/**
	 * @param int[] $levelParentIds
	 * @return int[]
	 */
	private function fetchNextLevelParentIds(array $levelParentIds, ?TypeCondition $typeCondition): array
	{
		// A join or EXISTS lets the optimizer start from the whole chat table on skewed PARENT_ID statistics.
		// A scalar COUNT subquery keeps the plan on the IN list with one index lookup per child;
		// LIMIT 1 or MIN() instead would let PostgreSQL pick a sequential scan for each child.
		$childCountQuery = ChatTable::query()
			->setCustomBaseTableAlias(self::CHILD_ALIAS)
			->setSelect([new ExpressionField('CHILD_COUNT', 'COUNT(*)')])
			->where('PARENT_ID', new SqlExpression('%s'))
		;

		$query = ChatTable::query()
			->setSelect(['ID'])
			->whereIn('PARENT_ID', $levelParentIds)
			->where(Query::filter()->whereExpr("({$childCountQuery->getQuery()}) > 0", ['ID']))
		;

		if ($typeCondition !== null)
		{
			$typeFilter = new TypeFilter(condition: $typeCondition, typeField: 'TYPE', entityTypeField: 'ENTITY_TYPE');
			$query->where($typeFilter->toConditionTree());
		}

		return array_column($query->fetchAll(), 'ID');
	}
}
