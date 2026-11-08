<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\Model\Schedule\Shift\ShiftTable;
use Bitrix\Timeman\Model\Worktime\Record\EO_WorktimeRecord;
use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordTable;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Internal\Entity\Record\Record;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordCollection;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\RecordMapper;
use Bitrix\Timeman\V2\Internal\Service\RecordActionsResolver;

class RecordRepository
{
	private readonly WorktimeRepository $legacyWorktimeRepository;

	public function __construct(
		private readonly RecordMapper $workTimeRecordMapper,
		private readonly RecordActionsResolver $actionsResolver,
		?WorktimeRepository $legacyWorktimeRepository = null,
	)
	{
		$this->legacyWorktimeRepository = $legacyWorktimeRepository
			?? DependencyManager::getInstance()->getWorktimeRepository();
	}

	public function getById(
		int $recordId,
		bool $includeSchedule = true,
		bool $includeShift = true,
	): ?Record
	{
		$with = [];

		if ($includeSchedule)
		{
			$with[] = 'SCHEDULE';
		}

		if ($includeShift)
		{
			$with[] = 'SHIFT';
		}

		$record = $this->legacyWorktimeRepository->findByIdWith($recordId, $with);
		if (!$record)
		{
			return null;
		}

		return $this->workTimeRecordMapper->mapFromOrm($record, $includeSchedule, $includeShift);
	}

	public function exists(int $recordId): bool
	{
		return (bool)WorktimeRecordTable::query()
			->addSelect('ID')
			->where('ID', $recordId)
			->setLimit(1)
			->exec()
			->fetch();
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	public function belongsToUser(int $recordId, int $userId): bool
	{
		return (bool)WorktimeRecordTable::query()
			->addSelect('ID')
			->where('ID', $recordId)
			->where('USER_ID', $userId)
			->setLimit(1)
			->exec()
			->fetch()
		;
	}

	/**
	 * @return array{entryIds: int[], startById: array<int, int>}
	 */
	public function getEntryIdsWithStartTimestampsByUserAndRange(int $userId, int $fromTs, int $toTs): array
	{
		if ($userId <= 0 || $fromTs <= 0 || $toTs <= 0)
		{
			return ['entryIds' => [], 'startById' => []];
		}

		$rows = WorktimeRecordTable::query()
			->addSelect('ID')
			->addSelect('RECORDED_START_TIMESTAMP')
			->where('USER_ID', $userId)
			->where('RECORDED_START_TIMESTAMP', '>=', $fromTs)
			->where('RECORDED_START_TIMESTAMP', '<=', $toTs)
			->addOrder('RECORDED_START_TIMESTAMP', 'ASC')
			->exec()
			->fetchAll();

		$entryIds = [];
		$startById = [];
		foreach ($rows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$entryIds[] = $id;
			$startById[$id] = (int)($row['RECORDED_START_TIMESTAMP'] ?? 0);
		}

		return ['entryIds' => $entryIds, 'startById' => $startById];
	}

	/**
	 * Closed records of the user within the given UTC range, only the columns needed to take the median
	 * local start of the day: RECORDED_START_TIMESTAMP (unix UTC) and START_OFFSET (user utc offset).
	 *
	 * @return array<int, array{RECORDED_START_TIMESTAMP: int, START_OFFSET: int}>
	 */
	public function getClosedStartTimestampsByUserAndRange(int $userId, int $fromTs, int $toTs): array
	{
		if ($userId <= 0 || $fromTs <= 0 || $toTs <= 0)
		{
			return [];
		}

		$rows = WorktimeRecordTable::query()
			->addSelect('RECORDED_START_TIMESTAMP')
			->addSelect('START_OFFSET')
			->where('USER_ID', $userId)
			->where('CURRENT_STATUS', WorktimeRecordTable::STATUS_CLOSED)
			->where('RECORDED_START_TIMESTAMP', '>=', $fromTs)
			->where('RECORDED_START_TIMESTAMP', '<=', $toTs)
			->addOrder('RECORDED_START_TIMESTAMP', 'ASC')
			->exec()
			->fetchAll();

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = [
				'RECORDED_START_TIMESTAMP' => (int)($row['RECORDED_START_TIMESTAMP'] ?? 0),
				'START_OFFSET' => (int)($row['START_OFFSET'] ?? 0),
			];
		}

		return $result;
	}

	public function getCurrentRecord(
		int $userId,
		bool $includeSchedule = true,
		bool $includeShift = true,
	): ?Record
	{
		$record = $this->legacyWorktimeRepository->findLatestRecord($userId);
		if (!$record)
		{
			return null;
		}

		$state = $this->actionsResolver->getStateForRecord($record);

		return $this->workTimeRecordMapper->mapFromOrm($record, $includeSchedule, $includeShift, $state);
	}

	/**
	 * @param int[] $userIds
	 * @return array{
	 *     records: array<int, Record>,
	 *     failedUserIds: array<int, true>
	 * }
	 */
	public function getCurrentRecords(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));

		$result = [
			'records' => [],
			'failedUserIds' => [],
		];

		foreach (array_chunk($userIds, 300) as $userIdChunk)
		{
			try
			{
				$recordIdsByUser = $this->loadCurrentRecordIds($userIdChunk);
				$records = $this->loadRecordsByIds(array_values($recordIdsByUser));
				$this->fillSchedulesAndShifts($records);
			}
			catch (\Throwable)
			{
				$result['failedUserIds'] += array_fill_keys($userIdChunk, true);

				continue;
			}
			$userIdByRecordId = array_flip($recordIdsByUser);
			$loadedRecordIds = [];

			foreach ($records as $record)
			{
				$recordId = (int)$record->getId();
				$userId = (int)($userIdByRecordId[$recordId] ?? 0);
				$loadedRecordIds[$recordId] = true;
				if ($userId <= 0 || (int)$record->getUserId() !== $userId)
				{
					if ($userId > 0)
					{
						$result['failedUserIds'][$userId] = true;
					}

					continue;
				}
				try
				{
					$result['records'][$userId] = $this->workTimeRecordMapper->mapFromOrm(
						$record,
						true,
						true,
					);
				}
				catch (\Throwable)
				{
					$result['failedUserIds'][$userId] = true;
				}
			}
			foreach ($recordIdsByUser as $userId => $recordId)
			{
				if (!isset($loadedRecordIds[$recordId]))
				{
					$result['failedUserIds'][$userId] = true;
				}
			}
		}

		return $result;
	}

	/** @return array<int, int> */
	protected function loadCurrentRecordIds(array $userIds): array
	{
		$query = WorktimeRecordTable::query()
			->registerRuntimeField(new ExpressionField('MAX_ID', 'MAX(%s)', 'ID'))
			->setSelect(['USER_ID', 'MAX_ID'])
			->whereIn('USER_ID', $userIds)
			->setGroup(['USER_ID'])
			->exec()
		;

		$recordIdsByUser = [];
		while ($row = $query->fetch())
		{
			$userId = (int)($row['USER_ID'] ?? 0);
			$recordId = (int)($row['MAX_ID'] ?? 0);
			if ($userId > 0 && $recordId > 0)
			{
				$recordIdsByUser[$userId] = $recordId;
			}
		}

		return $recordIdsByUser;
	}

	protected function loadRecordsByIds(array $recordIds): \Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordCollection
	{
		if ($recordIds === [])
		{
			return new \Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordCollection();
		}

		return WorktimeRecordTable::query()
			->setSelect(['*'])
			->whereIn('ID', $recordIds)
			->exec()
			->fetchCollection()
		;
	}

	private function fillSchedulesAndShifts(
		\Bitrix\Timeman\Model\Worktime\Record\WorktimeRecordCollection $records,
	): void
	{
		$scheduleIds = array_values(array_unique(array_filter(
			array_map('intval', $records->getScheduleIdList()),
			static fn (int $scheduleId): bool => $scheduleId > 0,
		)));
		if ($scheduleIds !== [])
		{
			$schedules = ScheduleTable::query()
				->setSelect(['*'])
				->whereIn('ID', $scheduleIds)
				->exec()
				->fetchCollection()
			;
			foreach ($records as $record)
			{
				$schedule = $schedules->getByPrimary($record->getScheduleId());
				if ($schedule !== null)
				{
					$record->defineSchedule($schedule);
				}
			}
		}

		$shiftIds = array_values(array_unique(array_filter(
			array_map('intval', $records->getShiftIdList()),
			static fn (int $shiftId): bool => $shiftId > 0,
		)));
		if ($shiftIds === [])
		{
			return;
		}

		$shifts = ShiftTable::query()
			->setSelect(['*'])
			->whereIn('ID', $shiftIds)
			->exec()
			->fetchCollection()
		;
		foreach ($records as $record)
		{
			$shift = $shifts->getByPrimary($record->getShiftId());
			if ($shift !== null)
			{
				$record->defineShift($shift);
			}
		}
	}

	public function getUsersRecords(
		array $userIds,
		?int $dateFrom,
		?int $dateTo,
		?array $select = null,
		?array $sort = null,
		bool $includeSchedule = false,
		bool $includeShift = false,
		int $offset = 0,
		int $limit = 50,
	): RecordCollection
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));
		$userIds = array_filter($userIds, static fn(int $id): bool => $id > 0);
		if (empty($userIds))
		{
			return new RecordCollection();
		}

		$query = WorktimeRecordTable::query()->setSelect(array_merge(['*'], $select ?: []));

		$query->whereIn('USER_ID', $userIds);

		if ($dateFrom !== null)
		{
			$query->where('RECORDED_START_TIMESTAMP', '>=', $dateFrom);
		}

		if ($dateTo !== null)
		{
			$query->where('RECORDED_START_TIMESTAMP', '<=', $dateTo);
		}

			$query->setOrder($sort ?: ['RECORDED_START_TIMESTAMP' => 'DESC']);

		$query->setOffset($offset)->setLimit($limit);

		$records = $query->exec()->fetchCollection();
		if ($records->count() === 0)
		{
			return new RecordCollection();
		}

		if ($includeSchedule)
		{
			$scheduleIds = array_filter($records->getScheduleIdList(), static fn($id): bool => (int)$id > 0);
			if (!empty($scheduleIds))
			{
				$schedules = ScheduleTable::query()
					->addSelect('*')
					->whereIn('ID', $scheduleIds)
					->exec()
					->fetchCollection();

				foreach ($records as $record)
				{
					if (
						$record->getScheduleId() > 0
						&& ($schedule = $schedules->getByPrimary($record->getScheduleId()))
					)
					{
						$record->defineSchedule($schedule);
					}
				}
			}
		}

		if ($includeShift)
		{
			$shiftIds = array_filter($records->getShiftIdList(), static fn($id): bool => (int)$id > 0);
			if (!empty($shiftIds))
			{
				$shifts = ShiftTable::query()
					->addSelect('*')
					->whereIn('ID', $shiftIds)
					->exec()
					->fetchCollection();

				foreach ($records as $record)
				{
					if (
						$record->getShiftId() > 0
						&& ($shift = $shifts->getByPrimary($record->getShiftId()))
					)
					{
						$record->defineShift($shift);
					}
				}
			}
		}

		return $this->workTimeRecordMapper->mapToCollectionFromOrm($records, $includeSchedule, $includeShift);
	}

	/**
	 * @return int[]
	 */
	public function getRecordIdsForPeriod(int $userId, int $dateFrom, int $dateTo): array
	{
		$records = WorktimeRecordTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->where('RECORDED_START_TIMESTAMP', '>=', $dateFrom)
			->where('RECORDED_START_TIMESTAMP', '<=', $dateTo)
			->exec()
			->fetchAll();

		return array_map('intval', array_column($records, 'ID'));
	}

	public function convertFieldsToCompatibility(EO_WorktimeRecord $record): array
	{
		return WorktimeRecordTable::convertFieldsCompatible($record->collectValues());
	}
}
