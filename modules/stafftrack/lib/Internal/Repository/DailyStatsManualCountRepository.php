<?php

namespace Bitrix\StaffTrack\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Type\Date;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;

class DailyStatsManualCountRepository
{
	/**
	 * @return array<int, array{ID: int, USER_ID: int, LOCAL_DATE: Date}>
	 */
	public function getUncountedRows(Date $from, Date $closedUntil, int $afterId, int $limit): array
	{
		$rows = CheckInDailyStatsTable::query()
			->setSelect(['ID', 'USER_ID', 'LOCAL_DATE'])
			->whereNull('MANUAL_CNT')
			->where('LOCAL_DATE', '>=', $from)
			->where('LOCAL_DATE', '<=', $closedUntil)
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn (array $row): array => [
				'ID' => (int)$row['ID'],
				'USER_ID' => (int)$row['USER_ID'],
				'LOCAL_DATE' => $row['LOCAL_DATE'],
			],
			$rows,
		);
	}

	public function hasUncounted(Date $from): bool
	{
		$row = CheckInDailyStatsTable::query()
			->setSelect(['ID'])
			->whereNull('MANUAL_CNT')
			->where('LOCAL_DATE', '>=', $from)
			->setLimit(1)
			->fetch()
		;

		return is_array($row);
	}

	public function fillManualCount(int $id, int $count): void
	{
		$tableName = CheckInDailyStatsTable::getTableName();

		Application::getConnection()->queryExecute(
			"UPDATE {$tableName} SET MANUAL_CNT = {$count} WHERE ID = {$id} AND MANUAL_CNT IS NULL",
		);
	}
}
