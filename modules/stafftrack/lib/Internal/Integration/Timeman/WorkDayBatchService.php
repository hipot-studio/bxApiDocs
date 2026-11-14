<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Integration\Timeman;

use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

/**
 * The work day of a whole team on one date, cut out of the records of that date: the current-state method of
 * work time tracking ignores the moment it is given.
 *
 * One query per chunk, the window widened by a day on both sides: the filter works by the record date, and a
 * local day spans two of them (a shift over WorktimeRecordManager::NEUTRAL_THRESHOLD_SECONDS is abnormal).
 * The read checks no rights, whom it is made for is narrowed by filterReadableUserIds() beforehand.
 */
class WorkDayBatchService
{
	private const USER_CHUNK_SIZE = 300;

	/** Far above a real three-day window; a chunk that fills its page is left untrusted rather than cut. */
	private const RECORDS_PER_USER_LIMIT = 10;

	private const WINDOW_MARGIN_SECONDS = 86400;

	private const STATUS_CLOSED = 'closed';
	private const STATUS_PAUSED = 'paused';

	private ?RecordProvider $recordProvider = null;

	/**
	 * The employees whose work time the user of the request may read, in the given order. Work time tracking
	 * is asked about the whole team at once and resolves the users he may read once for it: O(N + M) for a team
	 * of N against M such users. A timeman older than that check is asked about every employee, at O(M) a check
	 * and O(N * M) the team.
	 *
	 * Whatever work time tracking hands back, only the given employees are kept: the reads made for them check
	 * no rights.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterReadableUserIds(array $userIds): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$readableUserIds = array_flip($this->readReadableUserIds($userIds));

		return array_values(array_filter(
			$userIds,
			static fn (int $userId): bool => isset($readableUserIds[$userId]),
		));
	}

	/**
	 * @param int[] $userIds
	 * @param string $localDate date as it is named where the employee lives, 'Y-m-d'
	 * @param array<int, string> $timeZoneByUserId IANA zone id per employee
	 * @param bool $isToday whether that date is the one being lived through
	 * @return array<int, DayStateDto>
	 */
	public function getDayStates(array $userIds, string $localDate, array $timeZoneByUserId, bool $isToday): array
	{
		$userIds = self::normalizeUserIds($userIds);
		if ($userIds === [])
		{
			return [];
		}

		$states = [];
		foreach (array_chunk($userIds, self::USER_CHUNK_SIZE) as $chunk)
		{
			$states += $this->getChunkDayStates($chunk, $localDate, $timeZoneByUserId, $isToday);
		}

		return $states;
	}

	/**
	 * The records of the window, off one read of work time tracking.
	 *
	 * @param int[] $userIds
	 * @return array<int, Record>
	 */
	protected function readRecords(array $userIds, int $dateFrom, int $dateTo, int $limit): array
	{
		$recordCollection = (new RecordProvider())->getRecords(
			new ListParams(
				pager: new Pager(limit: $limit, offset: 0),
				filter: new Filter(
					userIds: $userIds,
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			)
		);

		$records = [];
		foreach ($recordCollection as $record)
		{
			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Whose work time the user of the request may read, as work time tracking answers it: one question about
	 * the whole team, or a question per employee to a timeman older than that check.
	 *
	 * @param int[] $userIds
	 * @return int[]
	 */
	protected function readReadableUserIds(array $userIds): array
	{
		if (!$this->isTeamRightCheckAvailable())
		{
			return array_values(array_filter(
				$userIds,
				fn (int $userId): bool => $this->canReadWorktime($userId),
			));
		}

		return $this->getRecordProvider()->filterReadableWorktimeUserIds($userIds);
	}

	/**
	 * Whether the installed timeman checks a team at once; the one before it checks an employee alone.
	 */
	protected function isTeamRightCheckAvailable(): bool
	{
		return method_exists(RecordProvider::class, 'filterReadableWorktimeUserIds');
	}

	protected function canReadWorktime(int $userId): bool
	{
		return $this->getRecordProvider()->canReadWorktime($userId);
	}

	private function getRecordProvider(): RecordProvider
	{
		return $this->recordProvider ??= new RecordProvider();
	}

	/**
	 * @param int[] $userIds
	 * @param array<int, string> $timeZoneByUserId
	 * @return array<int, DayStateDto>
	 */
	private function getChunkDayStates(
		array $userIds,
		string $localDate,
		array $timeZoneByUserId,
		bool $isToday
	): array
	{
		$boundsByUserId = [];
		foreach ($userIds as $userId)
		{
			$bounds = self::resolveLocalDayBounds($localDate, $timeZoneByUserId[$userId] ?? '');
			if ($bounds !== null)
			{
				$boundsByUserId[$userId] = $bounds;
			}
		}

		['records' => $recordsByUserId, 'truncated' => $truncated] = $boundsByUserId === []
			? ['records' => [], 'truncated' => false]
			: $this->readChunkRecords($boundsByUserId)
		;

		$states = [];
		foreach ($userIds as $userId)
		{
			if (!isset($boundsByUserId[$userId]))
			{
				// the zone of this employee did not resolve
				$states[$userId] = DayStateDto::untrusted();

				continue;
			}

			if ($recordsByUserId === null)
			{
				$states[$userId] = $truncated ? DayStateDto::truncated() : DayStateDto::untrusted();

				continue;
			}

			[$dayStart, $dayEnd] = $boundsByUserId[$userId];
			$states[$userId] = self::buildDayState($recordsByUserId[$userId] ?? [], $dayStart, $dayEnd, $isToday);
		}

		return $states;
	}

	/**
	 * One query for the whole chunk over its widest local day plus the margin. A failed read and a read that
	 * filled its page, dropping the earliest records, both leave the chunk unread.
	 *
	 * @param array<int, array{0: int, 1: int}> $boundsByUserId
	 * @return array{records: array<int, array<int, Record>>|null, truncated: bool} records are null when the
	 *     chunk could not be read whole
	 */
	private function readChunkRecords(array $boundsByUserId): array
	{
		$userIds = array_keys($boundsByUserId);
		$limit = count($userIds) * self::RECORDS_PER_USER_LIMIT;

		try
		{
			$records = $this->readRecords(
				$userIds,
				min(array_column($boundsByUserId, 0)) - self::WINDOW_MARGIN_SECONDS,
				max(array_column($boundsByUserId, 1)) + self::WINDOW_MARGIN_SECONDS - 1,
				$limit,
			);
		}
		catch (\Throwable)
		{
			return ['records' => null, 'truncated' => false];
		}

		if (count($records) >= $limit)
		{
			return ['records' => null, 'truncated' => true];
		}

		$recordsByUserId = [];
		foreach ($records as $record)
		{
			$recordsByUserId[$record->userId][] = $record;
		}

		return ['records' => $recordsByUserId, 'truncated' => false];
	}

	/**
	 * The day made of the shifts begun inside the local day, from the earliest start to the latest close, as
	 * the panel of the employee reads it (WorkDayService::getDay). The state is the one of the last shift begun.
	 *
	 * @param array<int, Record> $records
	 */
	private static function buildDayState(array $records, int $dayStart, int $dayEnd, bool $isToday): DayStateDto
	{
		$dayRecords = [];
		foreach ($records as $record)
		{
			$startTime = $record->startTime;
			if ($startTime !== null && $startTime >= $dayStart && $startTime < $dayEnd)
			{
				$dayRecords[] = $record;
			}
		}

		if ($dayRecords === [])
		{
			return DayStateDto::notStarted();
		}

		$status = self::lastStartedRecord($dayRecords)->state->status->value;
		$closed = $status === self::STATUS_CLOSED;

		return new DayStateDto(
			trusted: true,
			started: true,
			closed: $closed,
			paused: $isToday && $status === self::STATUS_PAUSED,
			startTs: self::earliestStart($dayRecords),
			endTs: $closed ? self::latestClose($dayRecords) : null,
		);
	}

	/**
	 * The shift the employee began last, whatever order work time tracking handed the records in.
	 *
	 * @param array<int, Record> $dayRecords not empty
	 */
	private static function lastStartedRecord(array $dayRecords): Record
	{
		$last = $dayRecords[0];
		foreach ($dayRecords as $record)
		{
			if ((int)$record->startTime >= (int)$last->startTime)
			{
				$last = $record;
			}
		}

		return $last;
	}

	/**
	 * @param array<int, Record> $dayRecords not empty
	 */
	private static function earliestStart(array $dayRecords): int
	{
		return min(array_map(static fn (Record $record): int => (int)$record->startTime, $dayRecords));
	}

	/**
	 * The latest close among the closed shifts; a close after midnight still belongs to the date of the shift.
	 *
	 * @param array<int, Record> $dayRecords
	 */
	private static function latestClose(array $dayRecords): ?int
	{
		$close = null;
		foreach ($dayRecords as $record)
		{
			$endTime = $record->endTime;
			if ($record->state->status->value !== self::STATUS_CLOSED || $endTime === null)
			{
				continue;
			}

			$close = $close === null ? $endTime : max($close, $endTime);
		}

		return $close;
	}

	/**
	 * The local day of the employee as two timestamps, the end exclusive: the next local midnight, so that a
	 * day of a daylight saving switch keeps its length. The midnight is that of the next date itself: a date
	 * whose midnight the clocks skip begins at the switch, and a day on from that start is already an hour into
	 * the next date.
	 *
	 * @return array{0: int, 1: int}|null null when the zone did not resolve
	 */
	private static function resolveLocalDayBounds(string $localDate, string $timeZoneId): ?array
	{
		if ($timeZoneId === '')
		{
			return null;
		}

		try
		{
			$timeZone = new \DateTimeZone($timeZoneId);
			$dayStart = new \DateTimeImmutable($localDate . ' 00:00:00', $timeZone);
			$nextDate = $dayStart->modify('+1 day')->format('Y-m-d');

			return [
				$dayStart->getTimestamp(),
				(new \DateTimeImmutable($nextDate . ' 00:00:00', $timeZone))->getTimestamp(),
			];
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * @param int[] $userIds
	 * @return int[]
	 */
	private static function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
