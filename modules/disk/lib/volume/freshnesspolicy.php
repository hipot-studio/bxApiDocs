<?php

declare(strict_types=1);

namespace Bitrix\Disk\Volume;

use Bitrix\Disk\Internals\VolumeTable;
use Bitrix\Main\Type\DateTime;

final class FreshnessPolicy
{
	private const FRESHNESS_PERIOD = 86400;

	public function evaluate(
		int $ownerId,
		?int $storageId = null,
		?string $indicatorType = null,
		?DateTime $now = null,
	): array
	{
		$filter = ['=OWNER_ID' => $ownerId];
		if ($storageId !== null)
		{
			$filter['=STORAGE_ID'] = $storageId;
		}
		if ($indicatorType !== null)
		{
			$filter['=INDICATOR_TYPE'] = $indicatorType;
		}

		$snapshot = VolumeTable::getList([
			'select' => ['ID', 'CREATE_TIME'],
			'filter' => $filter + ['=AGENT_LOCK' => Task::TASK_STATUS_NONE],
			'order' => ['CREATE_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => 1,
		])->fetch();
		if (!$snapshot)
		{
			return ['status' => 'unavailable', 'snapshotId' => null, 'generatedAt' => null];
		}

		$runningTask = VolumeTable::getList([
			'select' => ['ID'],
			'filter' => $filter + [
				'=AGENT_LOCK' => [Task::TASK_STATUS_WAIT, Task::TASK_STATUS_RUNNING],
			],
			'limit' => 1,
		])->fetch();
		$status = 'fresh';
		if ($runningTask)
		{
			$status = 'refresh_in_progress';
		}
		else
		{
			$finishedTask = VolumeTable::getList([
				'select' => ['ID'],
				'filter' => $filter + [
					'=AGENT_LOCK' => [Task::TASK_STATUS_DONE, Task::TASK_STATUS_CANCEL],
				],
				'limit' => 1,
			])->fetch();
			$now ??= new DateTime();
			if (
				$finishedTask
				|| $snapshot['CREATE_TIME']->getTimestamp() < $now->getTimestamp() - self::FRESHNESS_PERIOD
			)
			{
				$status = 'stale';
			}
		}

		return [
			'status' => $status,
			'snapshotId' => (int)$snapshot['ID'],
			'generatedAt' => $snapshot['CREATE_TIME']->format('c'),
		];
	}

	public function needsReload(int $ownerId, ?int $storageId = null): bool
	{
		return $this->evaluate($ownerId, $storageId)['status'] === 'stale';
	}
}
