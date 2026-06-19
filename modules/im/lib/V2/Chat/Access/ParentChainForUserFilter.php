<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Tree\TreeLevel;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

readonly class ParentChainForUserFilter
{
	public function __construct(
		private ChatAncestorNavigator $navigator,
		private int $userId,
		private TreeOrigin $origin,
		private int $maxDepth,
		private ?TypeCondition $openParentCondition,
	) {}

	public function apply(Query $query): void
	{
		$levels = $this->navigator->joinAncestors(
			$query,
			origin: $this->origin,
			depth: $this->maxDepth,
			joinType: Join::TYPE_LEFT,
			joinLastLevel: true,
		);

		$condition = new ConditionTree();

		foreach ($levels as $level)
		{
			if ($level->depth === 0)
			{
				continue;
			}

			$this->registerRelationJoin($query, $level);
			$condition->where($this->buildLevelCondition($level));
		}

		$query->where($condition);
	}

	private function registerRelationJoin(Query $query, TreeLevel $level): void
	{
		$relAlias = "PARENT_REL_{$this->userId}_{$level->depth}";
		$query->registerRuntimeField(
			$relAlias,
			new Reference(
				$relAlias,
				RelationTable::class,
				Join::on('ref.CHAT_ID', "this.{$level->idExpression}")
					->where('ref.USER_ID', $this->userId),
				['join_type' => Join::TYPE_LEFT],
			),
		);
	}

	private function buildLevelCondition(TreeLevel $level): ConditionTree
	{
		$filter = Query::filter()->logic('or')
			->whereNull($level->idExpression)
			->where($level->idExpression, 0)
			->whereNotNull("PARENT_REL_{$this->userId}_{$level->depth}.ID")
		;

		if ($this->openParentCondition !== null && $level->alias !== null)
		{
			$filter->where(
				(new TypeFilter(
					$this->openParentCondition,
					"{$level->alias}.TYPE",
					"{$level->alias}.ENTITY_TYPE",
				))->toConditionTree()
			);
		}

		return $filter;
	}
}
