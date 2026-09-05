<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Relation\Provider;

use Bitrix\Extranet\Model\ExtranetUserTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

class RelationProvider
{
	use ContextCustomer;

	public const ROLE_PRIORITY_MAP = [
		Chat::ROLE_OWNER => 1,
		Chat::ROLE_MANAGER => 2,
		Chat::ROLE_MEMBER => 3,
	];

	/**
	 * Group ranks for grouped sort (ALG-01, withGroupSort=true).
	 * Role is stronger than type: owner -> manager -> guest(collaber) -> member.
	 */
	public const GROUP_RANK_OWNER = 1;
	public const GROUP_RANK_MANAGER = 2;
	public const GROUP_RANK_GUEST = 3;
	public const GROUP_RANK_MEMBER = 4;

	protected const COLLABER_ROLE = 'collaber';
	protected const EXTRANET_COLLABER_ALIAS = 'EXTRANET_COLLABER';

	protected Chat $chat;

	public function __construct(int $chatId)
	{
		$this->chat = Chat::getInstance($chatId);
	}

	public function getMembers(?int $limit = 50, ?RelationCursor $cursor = null, bool $withGroupSort = false): RelationCollection
	{
		if ($withGroupSort)
		{
			return $this->getMembersGrouped($limit, $cursor);
		}

		$query = RelationTable::query()
			->setSelect(RelationCollection::COMMON_FIELDS)
			->where('CHAT_ID', (int)$this->chat->getId())
			->where('IS_HIDDEN', false)
			->where('USER.ACTIVE', true)
			->setOrder(['ROLE_PRIORITY', 'ID'])
			->setLimit($limit)
		;

		$this->prepareQuery($query, $cursor);

		return new RelationCollection($query->fetchAll());
	}

	protected function getMembersGrouped(?int $limit, ?RelationCursor $cursor): RelationCollection
	{
		$query = RelationTable::query()
			->setSelect(RelationCollection::COMMON_FIELDS)
			->where('CHAT_ID', (int)$this->chat->getId())
			->where('IS_HIDDEN', false)
			->where('USER.ACTIVE', true)
			->setOrder(['GROUP_RANK' => 'ASC', 'ID' => 'DESC'])
			->setLimit($limit)
		;

		$this->prepareGroupedQuery($query, $cursor);

		return new RelationCollection($query->fetchAll());
	}

	public function getAllMemberIds(): array
	{
		return $this
			->chat
			->getRelations()
			->filter(static fn (Relation $relation) => !$relation->isHidden())
			->getUserIds()
		;
	}

	/**
	 * Builds the nextCursor for grouped sort from the type-aware rank of the last element of the
	 * page (min ID of the lowest group present), NOT from Relation::getRole(). Returns null when
	 * the page is not full, so there is nothing to continue with.
	 */
	public function getNextGroupedCursor(RelationCollection $relations, int $limit): ?RelationCursor
	{
		if ($relations->count() < $limit)
		{
			return null;
		}

		// Invariant: with ORDER BY GROUP_RANK ASC, ID DESC the last SQL row of the page is the
		// min ID of the lowest group present. RelationCollection stores items as an associative
		// map keyed by primary ID, so we materialize a positional list instead of relying on the
		// container's iteration order to pick the last row.
		$rows = array_values(iterator_to_array($relations));
		$last = end($rows) ?: null;

		if ($last === null || $last->getId() === null)
		{
			return null;
		}

		return new RelationCursor($this->getCursorRole($last), (int)$last->getId());
	}

	protected function getCursorRole(Relation $relation): string
	{
		if ((int)$relation->getUserId() === (int)$this->chat->getAuthorId())
		{
			return Chat::ROLE_OWNER;
		}

		if ($relation->getManager())
		{
			return Chat::ROLE_MANAGER;
		}

		// Resolve the collaber flag from the same live registry the grouping JOIN reads
		// (b_extranet_user, ROLE='collaber'), not from the TTL-cached CollaberService. A stale cache
		// could rank the cursor differently from the page it closes and skip or duplicate a row on
		// the group boundary of the next page.
		if ($this->isCollaberRelation((int)$relation->getUserId()))
		{
			return RelationCursor::ROLE_GUEST_GROUP;
		}

		return Chat::ROLE_MEMBER;
	}

	protected function isCollaberRelation(int $userId): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		return (bool)ExtranetUserTable::query()
			->where('USER_ID', $userId)
			->where('ROLE', self::COLLABER_ROLE)
			->setSelect(['USER_ID'])
			->setLimit(1)
			->fetch()
		;
	}

	protected function prepareQuery(Query $query, ?RelationCursor $cursor): void
	{
		$this->defineRoles($query);
		ServiceLocator::getInstance()->get(ParentChainFilterFactory::class)
			->forChat((int)$this->chat->getId())
			->apply($query);
		if ($cursor)
		{
			$this->applyCursor($query, $cursor);
		}
	}

	protected function prepareGroupedQuery(Query $query, ?RelationCursor $cursor): void
	{
		$this->defineGroupRank($query);
		ServiceLocator::getInstance()->get(ParentChainFilterFactory::class)
			->forChat((int)$this->chat->getId())
			->apply($query);
		if ($cursor)
		{
			$this->applyGroupedCursor($query, $cursor);
		}
	}

	protected function defineRoles(Query $query): void
	{
		$chatAuthorId = (int)$this->chat->getAuthorId();
		$ownerRole = Chat::ROLE_OWNER;
		$managerRole = Chat::ROLE_MANAGER;
		$memberRole = Chat::ROLE_MEMBER;
		$rolePriorityMap = self::ROLE_PRIORITY_MAP;

		$roleField = new ExpressionField(
			'ROLE',
			"CASE
				WHEN %s = {$chatAuthorId} THEN '{$ownerRole}'
				WHEN %s = 'Y' THEN '{$managerRole}'
				ELSE '{$memberRole}'
			END",
			['USER_ID', 'MANAGER']
		);
		$query->registerRuntimeField('ROLE', $roleField);

		$rolePriorityField = new ExpressionField(
			'ROLE_PRIORITY',
			"CASE
				WHEN %s = '{$ownerRole}' THEN {$rolePriorityMap[$ownerRole]}
				WHEN %s = '{$managerRole}' THEN {$rolePriorityMap[$managerRole]}
				ELSE {$rolePriorityMap[$memberRole]}
			END",
			['ROLE', 'ROLE']
		);
		$rolePriorityField->configureValueType(IntegerField::class);
		$query->registerRuntimeField($rolePriorityField);
	}

	/**
	 * Grouped rank per ALG-01. Role beats type: owner -> manager -> guest(collaber) -> member.
	 * Guest predicate is a LEFT JOIN to b_extranet_user (ROLE='collaber'); absence of the
	 * joined row keeps the relation in the "member" group. Without the extranet module the
	 * join is not registered and the guest branch always evaluates to false.
	 */
	protected function defineGroupRank(Query $query): void
	{
		$chatAuthorId = (int)$this->chat->getAuthorId();
		$rankOwner = self::GROUP_RANK_OWNER;
		$rankManager = self::GROUP_RANK_MANAGER;
		$rankGuest = self::GROUP_RANK_GUEST;
		$rankMember = self::GROUP_RANK_MEMBER;

		$guestArgs = [];
		$guestWhen = '';
		if ($this->registerGuestJoin($query))
		{
			$guestWhen = "WHEN %s IS NOT NULL THEN {$rankGuest}\n\t\t\t\t";
			$guestArgs[] = self::EXTRANET_COLLABER_ALIAS . '.ID';
		}

		$groupRankField = new ExpressionField(
			'GROUP_RANK',
			"CASE
				WHEN %s = {$chatAuthorId} THEN {$rankOwner}
				WHEN %s = 'Y' THEN {$rankManager}
				{$guestWhen}ELSE {$rankMember}
			END",
			array_merge(['USER_ID', 'MANAGER'], $guestArgs)
		);
		$groupRankField->configureValueType(IntegerField::class);
		$query->registerRuntimeField($groupRankField);
	}

	protected function registerGuestJoin(Query $query): bool
	{
		if (!Loader::includeModule('extranet'))
		{
			return false;
		}

		$collaberRole = self::COLLABER_ROLE;
		$query->registerRuntimeField(
			self::EXTRANET_COLLABER_ALIAS,
			new Reference(
				self::EXTRANET_COLLABER_ALIAS,
				ExtranetUserTable::class,
				Join::on('this.USER_ID', 'ref.USER_ID')
					->where('ref.ROLE', $collaberRole),
				['join_type' => Join::TYPE_LEFT],
			)
		);

		return true;
	}

	protected function applyCursor(Query $query, RelationCursor $cursor): void
	{
		$rolePriority = self::ROLE_PRIORITY_MAP[$cursor->role] ?? self::ROLE_PRIORITY_MAP[Chat::ROLE_MEMBER];
		$filter = Query::filter()
			->logic('or')
			->where('ROLE_PRIORITY', '>', $rolePriority)
			->where(
				Query::filter()
					->where('ROLE_PRIORITY', $rolePriority)
					->where('ID', '>', $cursor->relationId)
			)
		;
		$query->where($filter);
	}

	protected function applyGroupedCursor(Query $query, RelationCursor $cursor): void
	{
		$rank = RelationCursor::rankByRole($cursor->role);
		$filter = Query::filter()
			->logic('or')
			->where('GROUP_RANK', '>', $rank)
			->where(
				Query::filter()
					->where('GROUP_RANK', $rank)
					->where('ID', '<', $cursor->relationId)
			)
		;
		$query->where($filter);
	}
}
