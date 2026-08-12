<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Filters chats by ancestor chain.
 *
 * Root matching semantics:
 * - parentId > 0: a matching root must be a direct child of the given parent chat
 * - parentId = 0: a matching root must be top-level
 * - parentId = null: a matching root may exist on any level
 *
 * The depth is measured in descendant levels starting from direct children, just like
 * ParentChainForUserFilter measures ancestors starting from direct parents:
 * - 1: direct children
 * - 2: direct children and grandchildren
 * - 3: direct children, grandchildren and great-grandchildren
 *
 * Values below 1 are normalized to 1.
 */
final class ChatAncestorFilter
{
	private readonly TreeOrigin $origin;

	public function __construct(
		private readonly ChatAncestorNavigator $navigator,
		private readonly ?int $parentId,
		private readonly ?TypeCondition $typeCondition = null,
		private readonly int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
		?TreeOrigin $origin = null,
	)
	{
		$this->origin = $origin ?? TreeOrigin::forChat('CHAT');
	}

	public function apply(Query $query): void
	{
		$query->where($this->toConditionTree($query));
	}

	private function toConditionTree(Query $query): ConditionTree
	{
		$maxDepth = $this->getNormalizedDepth();
		$levels = $this->navigator->joinAncestors(
			$query,
			origin: $this->origin,
			depth: $maxDepth,
			joinType: Join::TYPE_LEFT,
		);

		$filter = Query::filter()->logic('OR');

		for ($depth = 1; $depth <= $maxDepth; $depth++)
		{
			$levelFilter = Query::filter();
			$candidateLevel = $levels[$depth - 1];
			$candidateParentIdField = $levels[$depth]->idExpression;

			if ($this->parentId !== null)
			{
				if ($this->parentId === 0)
				{
					$levelFilter->where(
						Query::filter()->logic('OR')
							->where($candidateParentIdField, 0)
							->whereNull($candidateParentIdField)
					);
				}
				else
				{
					$levelFilter->where($candidateParentIdField, $this->parentId);
				}
			}

			if ($this->typeCondition !== null)
			{
				$typeField = $candidateLevel->depth === 0 ? $this->origin->typeField : "{$candidateLevel->alias}.TYPE";
				$entityTypeField = $candidateLevel->depth === 0
					? $this->origin->entityTypeField
					: "{$candidateLevel->alias}.ENTITY_TYPE"
				;
				$typeFilter = new TypeFilter(
					condition: $this->typeCondition,
					typeField: $typeField,
					entityTypeField: $entityTypeField,
				);
				$levelFilter->where($typeFilter->toConditionTree());
			}

			if ($levelFilter->hasConditions())
			{
				$filter->where($levelFilter);
			}
		}

		if (!$filter->hasConditions())
		{
			$filter->where(new ExpressionField('ALWAYS_FALSE', '0'), '=', '1');
		}

		return $filter;
	}

	private function getNormalizedDepth(): int
	{
		return max(1, $this->maxDepth);
	}
}
