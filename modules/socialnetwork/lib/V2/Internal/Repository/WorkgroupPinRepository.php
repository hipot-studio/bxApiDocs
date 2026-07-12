<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Socialnetwork\WorkgroupPinTable;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;

class WorkgroupPinRepository implements WorkgroupPinRepositoryInterface
{
	public function getPinFlags(array $groupIds, int $userId, WorkgroupPinMode $mode): array
	{
		if (empty($groupIds) || $userId <= 0)
		{
			return [];
		}

		$result = [];

		$rows = WorkgroupPinTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => Query::filter()
				->where('GROUP_ID', 'in', $groupIds)
				->where('USER_ID', $userId)
				->where($this->getModeFilter($mode)),
		]);

		foreach ($rows as $row)
		{
			$result[(int)$row['GROUP_ID']] = true;
		}

		return $result;
	}

	private function getModeFilter(WorkgroupPinMode $mode): ConditionTree
	{
		$filter = Query::filter();

		return $mode === WorkgroupPinMode::Common
			? $filter
				->logic('or')
				->whereNull('CONTEXT')
				->where('CONTEXT', '')
			: $filter->where('CONTEXT', $mode->value)
		;
	}
}
