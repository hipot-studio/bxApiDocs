<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\V2\AccessCheckable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\ORM\Query\Query;

class RecentParams implements AccessCheckable
{
	public readonly RecentFilter $filter;
	public readonly ?int $limit;
	public readonly ?array $order;

	public function __construct(RecentFilter $filter, ?int $limit = null, ?array $order = null)
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

		$this->filter->prepareQuery($query);
	}

	public function checkAccess(?int $userId = null): Result
	{
		if ($this->filter->parentChatId)
		{
			return Chat::getInstance($this->filter->parentChatId)->checkAccess($userId);
		}

		return new Result();
	}
}
