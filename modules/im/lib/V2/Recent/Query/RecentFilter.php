<?php

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Type\DateTime;

class RecentFilter implements FilterInterface
{
	public readonly ?int $userId;
	public readonly ?string $itemType;
	public readonly ?string $entityType;
	public readonly ?DateTime $lastMessageDate;
	public readonly ?int $lastMessageId;
	public readonly bool $unreadOnly;
	public readonly array $chatIds;

	public function __construct(array $filter = [])
	{
		$this->userId = isset($filter['userId']) ? (int)$filter['userId'] : null;
		$this->itemType = isset($filter['itemType']) ? (string)$filter['itemType'] : null;
		$this->entityType = isset($filter['entityType']) ? (string)$filter['entityType'] : null;
		$this->lastMessageDate = $filter['lastMessageDate'] ?? null;
		$this->lastMessageId = isset($filter['lastMessageId']) ? (int)$filter['lastMessageId'] : null;
		$this->unreadOnly = isset($filter['unread']) && $filter['unread'] === 'Y';
		$this->chatIds = is_array($filter['chatIds'] ?? null) ? $filter['chatIds'] : [];
	}

	public function prepareFilter(): ConditionTree
	{
		$result = new ConditionTree();

		if (isset($this->userId))
		{
			$result->where('USER_ID', $this->userId);
		}

		if (isset($this->itemType))
		{
			$result->where('ITEM_TYPE', $this->itemType);
		}

		if (isset($this->entityType))
		{
			$result->where('CHAT.ENTITY_TYPE', $this->entityType);
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

		if (isset($this->unreadOnly) && $this->unreadOnly)
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
}
