<?php

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\V2\Chat\Access\ParentChainFilterFactory;
use Bitrix\Im\V2\Chat\Tree\ChatTreeFilterFactory;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Common\WithableTrait;
use Bitrix\Im\V2\Folder\Definition\Selection;
use Bitrix\Im\V2\Folder\Definition\SelectionBranch;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Provider\Params\PrepareQueryInterface;
use Bitrix\Main\Type\DateTime;

/**
 * @method self with(int $userId = null,?DateTime $lastMessageDate = null,?int $lastMessageId = null,bool $unreadOnly = null,array $chatIds = null,array $excludeChatIds = null,?string $recentSection = null,?int $parentChatId = null,?TypeCondition $typeCondition = null,?Selection $selection = null,bool $hideInvisibleCompanions = null,bool $hideJoinedPortalRows = null,)
 */
class RecentFilter implements FilterInterface, PrepareQueryInterface
{
	use WithableTrait;

	private const ROOT_PARENT_CHAT_ID = 0;

	public function __construct(
		public readonly int $userId,
		public readonly ?DateTime $lastMessageDate = null,
		public readonly ?int $lastMessageId = null,
		public readonly bool $unreadOnly = false,
		public readonly array $chatIds = [],
		public readonly array $excludeChatIds = [],
		public readonly ?string $recentSection = null,
		public readonly ?int $parentChatId = null,
		public readonly ?TypeCondition $typeCondition = null,
		public readonly ?Selection $selection = null,
		// The two composition rules the "All" folder puts on private conversations (ALG-02, ALG-03).
		// Off by default: every other recent query keeps the shape it had before the folder existed.
		public readonly bool $hideInvisibleCompanions = false,
		public readonly bool $hideJoinedPortalRows = false,
		private readonly ?TypeRegistry $typeRegistry = null,
	) {}

	public static function fromArray(array $filter = [], ?TypeRegistry $typeRegistry = null): self
	{
		return new self(
			userId: (int)$filter['userId'],
			lastMessageDate: $filter['lastMessageDate'] instanceof DateTime ? $filter['lastMessageDate'] : null,
			lastMessageId: isset($filter['lastMessageId']) ? (int)$filter['lastMessageId'] : null,
			unreadOnly: isset($filter['unread']) && $filter['unread'] === 'Y',
			chatIds: is_array($filter['chatIds'] ?? null) ? $filter['chatIds'] : [],
			excludeChatIds: is_array($filter['excludeChatIds'] ?? null) ? $filter['excludeChatIds'] : [],
			recentSection: isset($filter['recentSection']) ? (string)$filter['recentSection'] : null,
			parentChatId: self::resolveParentChatId($filter),
			typeCondition: $filter['typeCondition'] instanceof TypeCondition ? $filter['typeCondition'] : null,
			typeRegistry: $typeRegistry,
		);
	}

	public function isPossible(): bool
	{
		if ($this->selection !== null)
		{
			return !$this->selection->isEmpty();
		}

		return $this->resolveTypeCondition()->isPossible();
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		$result->where('USER_ID', $this->userId);

		if ($this->selection !== null)
		{
			$this->applySelectionFilter($result);
		}
		else
		{
			$this->applyTypeConditionFilter($result);

			if (isset($this->parentChatId))
			{
				$result->where('CHAT.PARENT_ID', $this->parentChatId);
			}
		}

		if (isset($this->lastMessageDate))
		{
			$result->where('DATE_LAST_ACTIVITY', '<=', $this->lastMessageDate);
		}

		if (isset($this->lastMessageId))
		{
			$result->where('LAST_MESSAGE_ID', '<', $this->lastMessageId);
		}

		if (!empty($this->chatIds))
		{
			$result->whereIn('ITEM_CID', $this->chatIds);
		}

		if (!empty($this->excludeChatIds))
		{
			$result->whereNotIn('ITEM_CID', $this->excludeChatIds);
		}

		return $result;
	}

	private function applyTypeConditionFilter(ConditionTree $result): void
	{
		$condition = $this->resolveTypeCondition();

		if ($condition->hasConditions())
		{
			$result->where((new TypeFilter($condition))->toConditionTree());
		}
	}

	/**
	 * Composite selection: OR of the member branches, each carrying its own recent
	 * section and root frame. A single branch is applied inline (no OR wrapper) so
	 * the section/personal path stays byte-for-byte the previous single-branch query.
	 */
	private function applySelectionFilter(ConditionTree $result): void
	{
		$branches = $this->selection->getBranches();

		if ($branches === [])
		{
			$result->where(new ExpressionField('ALWAYS_FALSE', '0'), '=', '1');

			return;
		}

		if (count($branches) === 1)
		{
			$this->applyBranchFilter($result, $branches[0]);

			return;
		}

		$union = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ($branches as $branch)
		{
			$branchTree = new ConditionTree();
			$this->applyBranchFilter($branchTree, $branch);
			$union->where($branchTree);
		}

		$result->where($union);
	}

	private function applyBranchFilter(ConditionTree $tree, SelectionBranch $branch): void
	{
		if ($branch->chatIds !== null)
		{
			$tree->whereIn('ITEM_CID', $branch->chatIds);
		}
		else
		{
			$condition = $this->resolveTypeCondition($branch->typeCondition);
			if ($condition->hasConditions())
			{
				$tree->where((new TypeFilter($condition))->toConditionTree());
			}
		}

		if ($branch->parentChatId !== null)
		{
			$tree->where('CHAT.PARENT_ID', $branch->parentChatId);
		}
	}

	public function prepareQuery(Query $query): void
	{
		$query->where($this->prepareFilter());

		if ($this->unreadOnly)
		{
			ServiceLocator::getInstance()
				->get(ChatTreeFilterFactory::class)
				->forUnread($this->userId)
				->apply($query);
		}

		if ($this->canIncludeNestedChats())
		{
			ServiceLocator::getInstance()
				->get(ParentChainFilterFactory::class)
				->forUser($this->userId, TreeOrigin::forChat('CHAT'))
				->apply($query);
		}

		if ($this->hideInvisibleCompanions)
		{
			ServiceLocator::getInstance()
				->get(CompanionVisibilityFilterFactory::class)
				->forReader($this->userId)
				->apply($query);
		}

		if ($this->hideJoinedPortalRows)
		{
			(new JoinedPortalRowFilter())->apply($query);
		}
	}

	private function canIncludeNestedChats(): bool
	{
		if ($this->selection !== null)
		{
			foreach ($this->selection->getBranches() as $branch)
			{
				if ($branch->chatIds === null && $branch->parentChatId !== self::ROOT_PARENT_CHAT_ID)
				{
					return true;
				}
			}

			return false;
		}

		return $this->parentChatId !== self::ROOT_PARENT_CHAT_ID;
	}

	private static function resolveParentChatId(array $filter): ?int
	{
		$parentId = $filter['parentId'] ?? null;

		return match (true)
		{
			(!array_key_exists('parentId', $filter)), ($parentId === null) => null,
			is_numeric($parentId) => (int)$parentId > 0 ? (int)$parentId : self::ROOT_PARENT_CHAT_ID,
			default => self::ROOT_PARENT_CHAT_ID,
		};
	}

	private function resolveTypeCondition(?string $recentSection = null): TypeCondition
	{
		$recentSection ??= $this->recentSection;
		$condition = $this->typeCondition ?? new TypeCondition();

		if (isset($recentSection))
		{
			$sectionCondition = $this->getTypeRegistry()->getConditionByRecentSection($recentSection);
			$condition = $condition->merge($sectionCondition);
		}

		return $condition;
	}

	private function getTypeRegistry(): TypeRegistry
	{
		return $this->typeRegistry ?? ServiceLocator::getInstance()->get(TypeRegistry::class);
	}
}
