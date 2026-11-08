<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Query;

use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Folder\Definition\Selection;
use Bitrix\Im\V2\Folder\Definition\SelectionBranch;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * The single place where a folder {@see Selection} becomes a condition over a chat
 * table. Mirrors {@see \Bitrix\Im\V2\Recent\Query\RecentFilter::applySelectionFilter()}
 * of the list side: branches are joined by OR, a single branch is applied inline.
 *
 * The translation is kept in two copies - this one for the search over the chat table, and
 * {@see \Bitrix\Im\V2\Recent\Query\RecentFilter::applySelectionFilter()} with
 * {@see \Bitrix\Im\V2\Recent\Query\RecentFilter::applyBranchFilter()} for the list over recent.
 * Both copies filter the same b_im_chat.PARENT_ID (the list reaches it through the CHAT
 * reference), so a frame is the same plain equality here and there. Three rules differ on
 * purpose. An empty chat id list and a branch that writes no condition at all both end in an
 * always-false guard here, while the list side writes nothing for them and relies on its caller
 * to cut an empty composition off upstream. The nesting vote counts chat-list branches here and
 * skips them there ({@see self::containsNestedChats()}).
 * An edit to any of the three has to be checked against the other copy.
 */
final class SelectionSearchFilter
{
	private const ROOT_PARENT_CHAT_ID = 0;

	public function __construct(
		private readonly Selection $selection,
		private readonly SelectionSearchFields $fields,
		private readonly ?TypeRegistry $typeRegistry = null,
	) {}

	public function toConditionTree(): ConditionTree
	{
		$branches = $this->selection->getBranches();

		if ($branches === [])
		{
			return $this->alwaysFalse();
		}

		if (count($branches) === 1)
		{
			return $this->branchTree($branches[0]);
		}

		$union = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ($branches as $branch)
		{
			$union->where($this->branchTree($branch));
		}

		return $union;
	}

	/**
	 * Whether the selection may reach beyond root chats, so the caller knows if the
	 * parent chain filter is needed. Decided over all branches at once: in folder mode
	 * there is no single frame for the whole query.
	 *
	 * Every branch without a root frame votes, chat-list branches included - unlike the list side
	 * ({@see \Bitrix\Im\V2\Recent\Query\RecentFilter::canIncludeNestedChats()}), which never routes
	 * a chat list through a selection. A listed chat may be nested: no write path guarantees
	 * root-only folder members, so the query protects itself instead of leaning on that invariant.
	 */
	public function containsNestedChats(): bool
	{
		foreach ($this->selection->getBranches() as $branch)
		{
			if ($branch->parentChatId !== self::ROOT_PARENT_CHAT_ID)
			{
				return true;
			}
		}

		return false;
	}

	private function branchTree(SelectionBranch $branch): ConditionTree
	{
		$tree = new ConditionTree();

		if ($branch->chatIds !== null)
		{
			// whereIn() writes no condition for an empty list, and under OR that would lift the restriction
			if ($branch->chatIds === [])
			{
				return $this->alwaysFalse();
			}

			$tree->whereIn($this->fields->chatIdField, $branch->chatIds);
		}
		elseif ($branch->typeCondition !== null)
		{
			$condition = $this->getTypeRegistry()->getConditionByRecentSection($branch->typeCondition);
			if ($condition->hasConditions())
			{
				$typeFilter = new TypeFilter($condition, $this->fields->typeField, $this->fields->entityTypeField);
				$tree->where($typeFilter->toConditionTree());
			}
		}

		// a root frame is the very same plain equality: PARENT_ID is nullable by declaration,
		// but a NULL parent fails the frame on both copies alike - parity over theoretical reach
		if ($branch->parentChatId !== null)
		{
			$tree->where($this->fields->parentField, $branch->parentChatId);
		}

		// a branch that wrote nothing would lift the folder restriction off the query
		if (!$tree->hasConditions())
		{
			return $this->alwaysFalse();
		}

		return $tree;
	}

	private function alwaysFalse(): ConditionTree
	{
		return (new ConditionTree())->where(new ExpressionField('ALWAYS_FALSE', '0'), '=', '1');
	}

	private function getTypeRegistry(): TypeRegistry
	{
		return $this->typeRegistry ?? ServiceLocator::getInstance()->get(TypeRegistry::class);
	}
}
