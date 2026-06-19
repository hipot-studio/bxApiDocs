<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;
use Bitrix\StaffTrack\Public\Services\CheckInAggregator;

class CheckInBacklogService
{
	private const USER_BATCH_LIMIT = 100;
	private const CHECK_IN_BATCH_LIMIT = 500;

	private const EXECUTION_TIME_LIMIT = 120;

	/**
	 * @return bool True if there are still unprocessed users (caller should reschedule).
	 */
	public function aggregateStaleCheckIns(): bool
	{
		$startTime = time();
		$now = new DateTime();
		$threshold = (clone $now)->add('-2 days');

		while (true)
		{
			if ((time() - $startTime) >= self::EXECUTION_TIME_LIMIT)
			{
				return true;
			}

			$userRes = CheckInTable::query()
				->setSelect(['USER_ID'])
				->where('DATE_CREATE', '<=', $threshold)
				->addGroup('USER_ID')
				->setLimit(self::USER_BATCH_LIMIT)
				->exec()
			;

			$userIds = [];
			while ($row = $userRes->fetch())
			{
				$userIds[] = (int)$row['USER_ID'];
			}

			if (empty($userIds))
			{
				return false;
			}

			foreach ($userIds as $userId)
			{
				if ((time() - $startTime) >= self::EXECUTION_TIME_LIMIT)
				{
					return true;
				}

				$this->aggregateUserStaleCheckIns($userId, $threshold);
			}
		}
	}

	private function aggregateUserStaleCheckIns(int $userId, DateTime $threshold): void
	{
		$repository = new \Bitrix\StaffTrack\Internal\Repository\CheckInRepository();
		$aggregator = new CheckInAggregator();

		$previousMaxId = 0;

		while (true)
		{
			$rows = $repository->getStaleCheckInRows($userId, $threshold, self::CHECK_IN_BATCH_LIMIT);

			if (empty($rows))
			{
				break;
			}

			$currentMaxId = max(array_column($rows, 'ID'));
			if ($currentMaxId <= $previousMaxId)
			{
				break;
			}
			$previousMaxId = $currentMaxId;

			$aggregator->aggregate($userId, $rows);
		}
	}
}
