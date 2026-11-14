<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Common\Normalize;
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
	private readonly ChatTreeLevelParents $levelParents;

	public function __construct(
		private readonly ChatAncestorNavigator $navigator,
		private readonly ?int $parentId,
		private readonly ?TypeCondition $typeCondition = null,
		private readonly int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
		?TreeOrigin $origin = null,
		?ChatTreeLevelParents $levelParents = null,
	)
	{
		$this->origin = $origin ?? TreeOrigin::forChat('CHAT');
		$this->levelParents = $levelParents ?? new ChatTreeLevelParents();
	}

	/**
	 * Adds the tree condition in the OR-across-levels form. For ID selections with parentId > 0
	 * use {@see self::fetchChatIds()}: it does not scan all the source rows of the user.
	 */
	public function apply(Query $query): void
	{
		$query->where($this->toConditionTree($query));
	}

	/**
	 * For parentId > 0 the source is filtered by the parents of each level, resolved from the chat tree first.
	 *
	 * @param \Closure(): Query $makeQuery returns a new source query without the tree condition on each call
	 * @return int[] unique positive IDs, order is not guaranteed
	 */
	public function fetchChatIds(\Closure $makeQuery, string $idField): array
	{
		if ($this->parentId === null || $this->parentId <= 0)
		{
			$query = $makeQuery();
			$this->apply($query);

			return $this->fetchIds($query, $idField);
		}

		return $this->fetchChatIdsByLevelParents($makeQuery, $idField);
	}

	/**
	 * @return int[]
	 */
	private function fetchChatIdsByLevelParents(\Closure $makeQuery, string $idField): array
	{
		$chatIds = $this->fetchIds($this->buildFirstLevelQuery($makeQuery()), $idField);

		$levelParentIds = [$this->parentId];
		for ($depth = 2; $depth <= $this->getNormalizedDepth(); $depth++)
		{
			$levelParentIds = $this->levelParents->findNextLevelParentIds(
				$levelParentIds,
				$depth === 2 ? $this->typeCondition : null,
			);
			if (empty($levelParentIds))
			{
				break;
			}

			foreach (array_chunk($levelParentIds, ChatTreeLevelParents::IN_CHUNK_SIZE) as $chunk)
			{
				$query = $makeQuery()->whereIn($this->origin->parentRef, $chunk);
				array_push($chatIds, ...$this->fetchIds($query, $idField));
			}
		}

		return Normalize::uniquePositiveIntegers($chatIds);
	}

	private function buildFirstLevelQuery(Query $query): Query
	{
		$query->where($this->origin->parentRef, $this->parentId);

		if ($this->typeCondition !== null)
		{
			$typeFilter = new TypeFilter(
				condition: $this->typeCondition,
				typeField: $this->origin->typeField,
				entityTypeField: $this->origin->entityTypeField,
			);
			$query->where($typeFilter->toConditionTree());
		}

		return $query;
	}

	/**
	 * @return int[]
	 */
	private function fetchIds(Query $query, string $idField): array
	{
		return Normalize::uniquePositiveIntegers(array_column($query->fetchAll(), $idField));
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
