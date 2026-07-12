<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Tree\TreeLevel;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

readonly class ParentChainForUserFilter
{
	public function __construct(
		private ChatAncestorNavigator $navigator,
		private SingleLevelAccessFilterFactory $singleLevelFilterFactory,
		private TypeRegistry $typeRegistry,
		private int $userId,
		private TreeOrigin $origin,
		private int $maxDepth,
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

		foreach ($levels as $level)
		{
			if ($level->depth === 0)
			{
				continue;
			}

			$this->registerRelationJoin($query, $level);
		}

		$query->where($this->buildChainCondition($levels));
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

	private function buildLevelAccessCondition(TreeLevel $level): ConditionTree
	{
		$singleLevelFilter = $this->singleLevelFilterFactory->forUser(
			userId: $this->userId,
			relationAlias: "PARENT_REL_{$this->userId}_{$level->depth}",
			chatAlias: $level->alias,
		);

		return Query::filter()->logic('or')
			->whereNull($level->idExpression)
			->where($level->idExpression, 0)
			->where($singleLevelFilter->toConditionTree())
		;
	}

	/**
	 * Builds: B0 OR (A1 AND (B1 OR (A2 AND ...))), where:
	 * Bn - source level does not require parent membership,
	 * An - target parent level is accessible or absent.
	 *
	 * @param TreeLevel[] $levels
	 */
	private function buildChainCondition(array $levels): ConditionTree
	{
		$condition = null;
		for ($depth = count($levels) - 1; $depth >= 1; $depth--)
		{
			$level = $levels[$depth] ?? null;
			if ($level === null)
			{
				continue;
			}

			$requiredCondition = $this->buildLevelAccessCondition($level);
			if ($condition !== null)
			{
				$requiredCondition = Query::filter()
					->where($requiredCondition)
					->where($condition)
				;
			}

			$boundaryCondition = $this->buildParentMembershipNotRequiredCondition($levels[$depth - 1] ?? null);
			if ($boundaryCondition === null)
			{
				$condition = $requiredCondition;
				continue;
			}

			$condition = Query::filter()->logic('or')
				->where($boundaryCondition)
				->where($requiredCondition)
			;
		}

		return $condition ?? new ConditionTree();
	}

	private function buildParentMembershipNotRequiredCondition(?TreeLevel $sourceLevel): ?ConditionTree
	{
		$typeCondition = $this->typeRegistry->getParentMembershipNotRequiredCondition();
		if ($typeCondition === null || $sourceLevel === null)
		{
			return null;
		}

		return (new TypeFilter(
			$typeCondition,
			$this->getChatField($sourceLevel->alias, 'TYPE'),
			$this->getChatField($sourceLevel->alias, 'ENTITY_TYPE'),
		))->toConditionTree();
	}

	private function getChatField(?string $alias, string $field): string
	{
		return $alias !== null ? "{$alias}.{$field}" : $field;
	}
}
