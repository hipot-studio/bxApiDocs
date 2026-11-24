<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RecentTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Mute\MuteProvider;
use Bitrix\Im\V2\Folder\Mute\UserMuteState;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

readonly class SubtreeUnreadFilter
{
	public function __construct(
		private ChatAncestorNavigator $navigator,
		private MuteProvider $muteProvider,
		private TypeRegistry $typeRegistry,
		private int $userId,
		private int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	) {}

	public function apply(Query $query): void
	{
		// Read once for the whole filter: the exclusion below lands on the outer query and on every
		// subquery it builds.
		$muteState = $this->muteProvider->getForUser($this->userId);

		$filter = Query::filter()->logic('OR');

		$this->addMessageCounterConditions($filter, $muteState);
		$this->addUnreadFlagConditions($filter, $muteState);

		$query->where($filter);

		if ($muteState->isEmpty())
		{
			// The query of a user with no rules keeps the shape it had.
			return;
		}

		// The chat itself. Inside the query and before the limit is applied: the clients tell there is
		// a next page by the length of this one, so a row dropped after the fetch would cut the list
		// short and hide everything below it without any error.
		//
		// The type of the chat is read from the recent row and the rest from the chat it points at:
		// the row carries neither the entity type nor the parent, and the join that brings them is
		// already there for the entity type alone.
		$this->excludeCovered(
			$query,
			$muteState,
			'ITEM_CID',
			new SectionColumns('ITEM_TYPE', 'CHAT.ENTITY_TYPE', 'CHAT.PARENT_ID'),
		);
	}

	private function addMessageCounterConditions(ConditionTree $filter, UserMuteState $muteState): void
	{
		$origin = TreeOrigin::forUnreadCounters();

		for ($depth = 0; $depth <= $this->maxDepth; $depth++)
		{
			$source = MessageUnreadTable::query()
				->where('USER_ID', $this->userId)
				->where('IS_MUTED', 'N')
			;

			$levels = $this->navigator->joinAncestors(
				$source,
				origin: $origin,
				depth: $depth,
				joinType: Join::TYPE_INNER,
			);
			$target = end($levels);

			if ($depth > 0)
			{
				$source->where($target->idExpression, '>', 0);
				$this->requireUnmutedAncestors($source, array_slice($levels, 1));
				$this->excludeCoveredAncestors($source, $muteState, array_slice($levels, 1));
				// The row the unread lies in is the level the ancestors are climbed from, and it has
				// to be sounding too: the manual mute of it is filtered above by the field of the row,
				// the rule of a folder has no field there and is checked the same way the ancestors
				// are. Without this an ancestor surfaced in the list because of a chat a rule keeps
				// silent - a collab because of the task chat inside it a muted "Tasks" folder covers.
				$this->excludeCovered(
					$source,
					$muteState,
					$origin->idExpression,
					new SectionColumns($origin->typeField, $origin->entityTypeField, $origin->parentRef),
				);
			}

			$source->setSelect([$target->idExpression]);
			$filter->whereIn('ITEM_CID', $source);
		}
	}

	private function addUnreadFlagConditions(ConditionTree $filter, UserMuteState $muteState): void
	{
		$filter->where(
			Query::filter()
				->where('UNREAD', true)
				->where('RELATION.NOTIFY_BLOCK', 'N')
		);

		for ($depth = 1; $depth <= $this->maxDepth; $depth++)
		{
			$source = RecentTable::query()
				->where('USER_ID', $this->userId)
				->where('UNREAD', true)
				->where('RELATION.NOTIFY_BLOCK', 'N')
			;

			$levels = $this->navigator->joinAncestors(
				$source,
				origin: TreeOrigin::forChat('CHAT'),
				depth: $depth,
				joinType: Join::TYPE_INNER,
			);
			$target = end($levels);

			$source->where($target->idExpression, '>', 0);
			$this->requireUnmutedAncestors($source, array_slice($levels, 1));
			$this->excludeCoveredAncestors($source, $muteState, array_slice($levels, 1));
			// The chat marked unread itself, for the reason given in the counter branch above.
			$this->excludeCovered($source, $muteState, 'ITEM_CID', SectionColumns::forChatAlias('CHAT'));
			$source->setSelect([$target->idExpression]);
			$filter->whereIn('ITEM_CID', $source);
		}
	}

	/**
	 * The same exclusion over the ancestors the subquery already joined, so that a descendant of a
	 * chat silenced by a rule keeps out of the unread-only list as well - next to the existing
	 * requirement that every ancestor is unmuted by hand ({@see requireUnmutedAncestors()}).
	 *
	 * No new join appears: the rows are already there, the id of an ancestor is the key they were
	 * joined by, and the type condition and the parent of the nesting frame both land on the columns
	 * of those very rows. The last level of the chain is joined by no row of its own
	 * ({@see ChatAncestorNavigator::doJoin()}, `joinLastLevel = false`), so only the exclusion by id
	 * is available there.
	 *
	 * @param TreeLevel[] $ancestorLevels
	 */
	private function excludeCoveredAncestors(Query $query, UserMuteState $muteState, array $ancestorLevels): void
	{
		if ($muteState->isEmpty())
		{
			return;
		}

		foreach ($ancestorLevels as $level)
		{
			$this->excludeCovered(
				$query,
				$muteState,
				$level->idExpression,
				$level->alias === null ? null : SectionColumns::forChatAlias($level->alias),
			);
		}
	}

	/**
	 * One level of one query: the chats of the muted personal folders are excluded by their ids, the
	 * sections of the muted system folders by the negation of the very type condition the section
	 * list itself is built from ({@see TypeRegistry::getConditionByRecentSection()}), narrowed to the
	 * nesting frames the section was muted from.
	 */
	private function excludeCovered(
		Query $query,
		UserMuteState $muteState,
		string $idField,
		?SectionColumns $columns,
	): void
	{
		$mutedChatIds = $muteState->getMutedChatIds();
		if ($mutedChatIds !== [])
		{
			$query->whereNotIn($idField, $mutedChatIds);
		}

		$sections = $muteState->getMutedSections();
		if ($columns === null || $sections === [])
		{
			return;
		}

		// One negation over the union of the sections and not one per section: NOT(A) AND NOT(B) is
		// NOT(A OR B), an equality three-valued logic keeps as well. The exclusion lands on the outer
		// query and on every subquery it builds, so a block saved here is saved in each of them.
		$covered = Query::filter()->logic('OR')->negative();

		foreach ($sections as $section => $frames)
		{
			$covered->where($this->coveredBySection($columns, (string)$section, $frames));
		}

		$query->where($covered);
	}

	/**
	 * One section of a rule: the type condition of the section and the nesting frames of the folders
	 * that muted it. Two folders can mute one section from different levels - the root folder of the
	 * section and the folder of the same section inside a collab - so the frames are a set.
	 *
	 * @param array<int, ?int> $frames
	 */
	private function coveredBySection(SectionColumns $columns, string $section, array $frames): ConditionTree
	{
		$typeFilter = new TypeFilter(
			condition: $this->typeRegistry->getConditionByRecentSection($section),
			typeField: $columns->typeField,
			entityTypeField: $columns->entityTypeField,
		);

		$condition = Query::filter()->where($typeFilter->toConditionTree());

		$frameCondition = $this->coveredByFrames($columns->parentField, $frames);
		if ($frameCondition !== null)
		{
			$condition->where($frameCondition);
		}

		return $condition;
	}

	/**
	 * The nesting frames of one section, or null when a folder of that section shows every level
	 * ({@see \Bitrix\Im\V2\Folder\BaseFolder::getRecentParentScope()}) and the section needs no frame
	 * at all.
	 *
	 * Every branch answers true or false and never unknown, which the negation above makes a
	 * requirement rather than a nicety: a branch answering unknown over a row whose parent column is
	 * null turns the whole negation unknown, and the row leaves the list instead of staying in it.
	 * Hence the null branches - a chat with no parent is a root chat, so the frame of the root is the
	 * one it belongs to.
	 *
	 * @param array<int, ?int> $frames
	 */
	private function coveredByFrames(string $parentField, array $frames): ?ConditionTree
	{
		$union = Query::filter()->logic('OR');

		foreach ($frames as $frame)
		{
			if ($frame === null)
			{
				return null;
			}

			$union->where(
				$frame === 0
					? Query::filter()->logic('OR')->whereNull($parentField)->where($parentField, 0)
					: Query::filter()->whereNotNull($parentField)->where($parentField, $frame)
			);
		}

		return $union;
	}

	/**
	 * @param TreeLevel[] $ancestorLevels
	 */
	private function requireUnmutedAncestors(Query $query, array $ancestorLevels): void
	{
		foreach ($ancestorLevels as $level)
		{
			$relAlias = "UNREAD_REL_{$this->userId}_{$level->depth}";

			$query->registerRuntimeField(
				$relAlias,
				(new Reference(
					$relAlias,
					RelationTable::class,
					Join::on('ref.CHAT_ID', "this.{$level->idExpression}")
						->where('ref.USER_ID', $this->userId),
				))->configureJoinType(Join::TYPE_INNER),
			);
			$query->where("{$relAlias}.NOTIFY_BLOCK", 'N');
		}
	}
}
