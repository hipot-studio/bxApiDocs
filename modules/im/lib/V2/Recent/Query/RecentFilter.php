<?php

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\V2\Chat\Type\Query\TypeFilter;
use Bitrix\Im\V2\Chat\Type\TypeCondition;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Common\WithableTrait;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Type\DateTime;

/**
 * @method self with(?int $userId = null,?DateTime $lastMessageDate = null,?int $lastMessageId = null,bool $unreadOnly = null,array $chatIds = null,?string $recentSection = null,?int $parentChatId = null,?TypeCondition $typeCondition = null,)
 */
class RecentFilter implements FilterInterface
{
	use WithableTrait;

	public function __construct(
		public readonly ?int $userId = null,
		public readonly ?DateTime $lastMessageDate = null,
		public readonly ?int $lastMessageId = null,
		public readonly bool $unreadOnly = false,
		public readonly array $chatIds = [],
		public readonly ?string $recentSection = null,
		public readonly ?int $parentChatId = null,
		public readonly ?TypeCondition $typeCondition = null,
		private readonly ?TypeRegistry $typeRegistry = null,
	) {}

	public static function fromArray(array $filter = [], ?TypeRegistry $typeRegistry = null): self
	{
		return new self(
			userId: isset($filter['userId']) ? (int)$filter['userId'] : null,
			lastMessageDate: $filter['lastMessageDate'] instanceof DateTime ? $filter['lastMessageDate'] : null,
			lastMessageId: isset($filter['lastMessageId']) ? (int)$filter['lastMessageId'] : null,
			unreadOnly: isset($filter['unread']) && $filter['unread'] === 'Y',
			chatIds: is_array($filter['chatIds'] ?? null) ? $filter['chatIds'] : [],
			recentSection: isset($filter['recentSection']) ? (string)$filter['recentSection'] : null,
			parentChatId: isset($filter['parentId']) ? (int)$filter['parentId'] : null,
			typeCondition: $filter['typeCondition'] instanceof TypeCondition ? $filter['typeCondition'] : null,
			typeRegistry: $typeRegistry,
		);
	}

	public function isPossible(): bool
	{
		return $this->resolveTypeCondition()->isPossible();
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->userId))
		{
			$result->where('USER_ID', $this->userId);
		}

		$this->applyTypeConditionFilter($result);

		if (isset($this->parentChatId))
		{
			$result->where('CHAT.PARENT_ID', $this->parentChatId);
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

		if ($this->unreadOnly)
		{
			$result->where(
				Query::filter()
					->logic('OR')
					->where('UNREAD', true)
					->where('HAS_UNREAD_MESSAGE', 1)
					->where('HAS_UNREAD_COMMENTS', 1)
			);
		}

		return $result;
	}

	private function resolveTypeCondition(): TypeCondition
	{
		$condition = $this->typeCondition ?? new TypeCondition();

		if (isset($this->recentSection))
		{
			$sectionCondition = $this->getTypeRegistry()->getConditionByRecentSection($this->recentSection);
			$condition = $condition->merge($sectionCondition);
		}

		return $condition;
	}

	private function applyTypeConditionFilter(ConditionTree $result): void
	{
		$condition = $this->resolveTypeCondition();

		if ($condition->hasConditions())
		{
			$result->where((new TypeFilter($condition))->toConditionTree());
		}
	}

	private function getTypeRegistry(): TypeRegistry
	{
		return $this->typeRegistry ?? ServiceLocator::getInstance()->get(TypeRegistry::class);
	}
}
