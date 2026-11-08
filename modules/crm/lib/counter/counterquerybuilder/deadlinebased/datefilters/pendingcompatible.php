<?php

namespace Bitrix\Crm\Counter\CounterQueryBuilder\DeadlineBased\DateFilters;

use Bitrix\Crm\Counter\CounterQueryBuilder\BuilderParams\QueryParams;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Crm\Counter\CounterQueryBuilder\QueryParts;
use Bitrix\Main\Type\DateTime;

final class PendingCompatible implements DateFilter
{
	private QueryParts\DeadlineBounds $deadlineBounds;

	public function __construct()
	{
		$this->deadlineBounds = new QueryParts\DeadlineBounds();
	}

	public function applyFilter(ConditionTree $ct, QueryParams $params): void
	{
		$lowBound = $this->deadlineBounds->getLowBound($params);
		$highBound = $this->deadlineBounds->getHighBound($params);

		if ($lowBound)
		{
			$ct->where('DEADLINE', '>=', (clone $lowBound)->toUserTime());
		}

		if ($highBound)
		{
			$ct->where('DEADLINE', '<=', $this->getUserTimeHighBound($highBound));
		}
	}

	private function getUserTimeHighBound(DateTime $highBound): DateTime
	{
		if ($this->deadlineBounds->isMaxBound($highBound))
		{
			return clone $highBound;
		}

		return (clone $highBound)->toUserTime();
	}
}
