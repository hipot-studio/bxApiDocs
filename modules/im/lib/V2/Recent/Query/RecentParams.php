<?php

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

class RecentParams
{
	public readonly ?RecentFilter $filter;
	public readonly ?int $limit;
	public readonly ?array $order;

	public function __construct(?RecentFilter $filter = null, ?int $limit = null, ?array $order = null)
	{
		$this->filter = $filter;
		$this->limit = $limit;
		$this->order = $order;
	}

	public function apply(Query $query): void
	{
		if ($this->limit !== null)
		{
			$query->setLimit($this->limit);
		}

		if (isset($this->order))
		{
			$query->setOrder($this->order);
		}

		if (isset($this->filter))
		{
			if ($this->filter->unreadOnly)
			{
				$this->registerRuntimeMessageUnreadFields($this->filter->userId ?? 0, $query);
			}

			$query->where($this->filter->prepareFilter());
		}
	}

	private function registerRuntimeMessageUnreadFields(int $userId, Query $query): void
	{
		$unreadTableName = MessageUnreadTable::getTableName();

		$query->registerRuntimeField(
			new ExpressionField(
				'HAS_UNREAD_MESSAGE',
				"EXISTS(SELECT 1 FROM {$unreadTableName} WHERE CHAT_ID = %s AND USER_ID = {$userId})",
				['ITEM_CID']
			)
		);

		$query->registerRuntimeField(
			new ExpressionField(
				'HAS_UNREAD_COMMENTS',
				"EXISTS(SELECT 1 FROM {$unreadTableName} WHERE PARENT_ID = %s AND USER_ID = {$userId} AND PARENT_ID > 0)",
				['ITEM_CID']
			)
		);
	}
}
