<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\Date;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;

class AnnualCheckInCountRepository
{
	/**
	 * @param int[] $userIds
	 * @return array<int, int>
	 */
	public function countByUsers(array $userIds, Date $from, Date $to): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$rows = CheckInDailyStatsTable::query()
			->registerRuntimeField(new ExpressionField('TOTAL', 'SUM(%s)', ['MANUAL_CNT']))
			->setSelect(['USER_ID', 'TOTAL'])
			->whereIn('USER_ID', $userIds)
			->where('LOCAL_DATE', '>=', $from)
			->where('LOCAL_DATE', '<', $to)
			->setGroup(['USER_ID'])
			->exec()
		;

		$totals = [];
		while ($row = $rows->fetch())
		{
			$totals[(int)$row['USER_ID']] = (int)$row['TOTAL'];
		}

		return $totals;
	}
}
