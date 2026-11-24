<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The chain condition of {@see ParentChainForUserFilter} for a driving set whose ancestors are the
 * same for every row and already known in PHP - a collab with its direct children.
 *
 * The condition is the same B0 OR (A1 AND (B1 OR (A2 AND ...))), where Bn says the source level
 * does not require parent membership and An says the target parent level is accessible or absent.
 * Only the levels differ: they are constants, so the type exemption of every ancestor and its
 * accessibility (a relation with it, or the open-chat exemption of {@see OpenChatAccessPolicy}) are
 * answered in PHP, and no ancestor row is lifted by PARENT_ID. SQL keeps at most one predicate on
 * the driving row - B0, the type exemption of the candidate itself - plus, in the member-column
 * form, one relation join per required level, because there the member is a column, not a constant.
 */
readonly class ParentChainForKnownAncestorsFilter
{
	private const RELATION_ALIAS_PREFIX = 'PARENT_ANC_REL_';
	private const TYPE_FIELD = 'TYPE';
	private const ENTITY_TYPE_FIELD = 'ENTITY_TYPE';

	/**
	 * @param ParentChainFilterFactory $ancestorRelations owner of the (user, chain) relation map; a
	 *        filter is built anew for every application, so the map cannot live here
	 *        ({@see ParentChainFilterFactory::getAncestorsWithRelation()}).
	 * @param int[] $ancestorIds ancestors of every driving row, ordered from the nearest parent up
	 * @param string|null $userIdColumn when set, the ancestor relations are matched against this
	 *        column of the query instead of the constant $userId, so one statement can serve many
	 *        users; $userId then only resolves the open-chat exemption
	 *        ({@see ParentChainFilterFactory::forKnownAncestors()}).
	 */
	public function __construct(
		private ParentChainFilterFactory $ancestorRelations,
		private OpenChatAccessPolicy $policy,
		private TypeRegistry $typeRegistry,
		private int $userId,
		private array $ancestorIds,
		private ?string $userIdColumn = null,
	) {}

	public function apply(Query $query): void
	{
		$requiredLevels = $this->resolveRequiredLevels();
		if ($requiredLevels === [])
		{
			return;
		}

		$typeExemption = $this->buildDrivingTypeExemption();

		if ($requiredLevels === null)
		{
			$query->where($typeExemption ?? self::unreachableCondition());

			return;
		}

		$chainCondition = Query::filter();
		foreach ($requiredLevels as $depth => $ancestorId)
		{
			$alias = $this->registerRelationJoin($query, $depth, $ancestorId);
			$chainCondition->whereNotNull("{$alias}.ID");
		}

		$query->where(
			$typeExemption === null
				? $chainCondition
				: Query::filter()->logic('or')->where($typeExemption)->where($chainCondition),
		);
	}

	/**
	 * Walks the chain outwards and stops where the condition stops asking: an ancestor exempt from
	 * parent membership makes every level above it irrelevant, and a level the caller did not name
	 * is an absent parent, which the joining form passes by its PARENT_ID IS NULL / = 0 branch.
	 *
	 * @return array<int,int>|null depth => ancestor id whose relation the statement has to check;
	 *         empty when the chain holds for every driving row, null when it holds for none
	 */
	private function resolveRequiredLevels(): ?array
	{
		$requiredLevels = [];
		$depth = 0;

		foreach ($this->ancestorIds as $ancestorId)
		{
			$depth++;
			$ancestor = Chat::getInstance($ancestorId);

			if (!$this->hasOpenChatExemption($ancestor))
			{
				if ($this->userIdColumn !== null)
				{
					$requiredLevels[$depth] = $ancestorId;
				}
				elseif (!$this->hasRelation($ancestorId))
				{
					return null;
				}
			}

			if ($this->hasParentMembershipExemption($ancestor))
			{
				break;
			}
		}

		return $requiredLevels;
	}

	/**
	 * The ancestor id is part of the alias: a query carrying two chains would otherwise register the
	 * same runtime field twice, and on a cloned entity {@see \Bitrix\Main\ORM\Entity::appendField()}
	 * skips the duplicate-name check, so the second join would silently replace the first and the
	 * surviving condition would check the relation with someone else's ancestor.
	 */
	private function registerRelationJoin(Query $query, int $depth, int $ancestorId): string
	{
		$alias = self::RELATION_ALIAS_PREFIX . $depth . '_' . $ancestorId;

		$query->registerRuntimeField(
			$alias,
			new Reference(
				$alias,
				RelationTable::class,
				Query::filter()
					->where('ref.CHAT_ID', $ancestorId)
					->whereColumn('ref.USER_ID', $this->userIdColumn),
				['join_type' => Join::TYPE_LEFT],
			),
		);

		return $alias;
	}

	/**
	 * B0 of the chain: the only predicate the single-user form can leave in SQL, because the type
	 * of the driving row is the only level unknown in PHP.
	 */
	private function buildDrivingTypeExemption(): ?ConditionTree
	{
		$condition = $this->typeRegistry->getParentMembershipNotRequiredCondition();
		if ($condition === null)
		{
			return null;
		}

		return (new TypeFilter($condition, self::TYPE_FIELD, self::ENTITY_TYPE_FIELD))->toConditionTree();
	}

	private static function unreachableCondition(): ConditionTree
	{
		return Query::filter()->where(new ExpressionField('PARENT_CHAIN_UNREACHABLE', '0'), '=', '1');
	}

	private function hasOpenChatExemption(Chat $ancestor): bool
	{
		if (!$this->policy->canSkipMembershipForOpenChats($this->userId))
		{
			return false;
		}

		$condition = $this->typeRegistry->getOpenTypeCondition();

		return $condition->hasConditions() && $this->matchesType($condition, $ancestor);
	}

	private function hasParentMembershipExemption(Chat $ancestor): bool
	{
		$condition = $this->typeRegistry->getParentMembershipNotRequiredCondition();

		return $condition !== null && $this->matchesType($condition, $ancestor);
	}

	/**
	 * PHP twin of {@see TypeFilter::toConditionTree()}: the driving row is matched by the statement
	 * and an ancestor row here, so both have to read the registry conditions the same way - an
	 * entry without an entity type covers every chat of its literal.
	 */
	private function matchesType(TypeCondition $condition, Chat $chat): bool
	{
		$literal = $chat->getType();
		$entityType = $chat->getEntityType();

		if ($condition->include !== null && !self::containsType($condition->include, $literal, $entityType))
		{
			return false;
		}

		return !self::containsType($condition->exclude, $literal, $entityType);
	}

	/**
	 * @param Chat\Type[] $types
	 */
	private static function containsType(array $types, string $literal, ?string $entityType): bool
	{
		foreach ($types as $type)
		{
			if ($type->literal === $literal && ($type->entityType === null || $type->entityType === $entityType))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Relation with the ancestor, taken for the whole chain at first need and shared by every filter
	 * of the hit built over the same chain.
	 */
	private function hasRelation(int $ancestorId): bool
	{
		$ancestorsWithRelation = $this->ancestorRelations->getAncestorsWithRelation($this->userId, $this->ancestorIds);

		return isset($ancestorsWithRelation[$ancestorId]);
	}
}
