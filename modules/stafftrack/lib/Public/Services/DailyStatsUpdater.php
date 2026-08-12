<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Model\CheckInDailyStatsTable;
use Bitrix\StaffTrack\Internal\Model\CheckInTable;

class DailyStatsUpdater
{
	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';

	public function update(int $userId, int $checkInId, int $entityType, string $description, int $userTimezone, int $timestamp): void
	{
		$localDate = $this->getLocalDate($timestamp, $userTimezone);
		$stats = $this->findStats($userId, $localDate) ?? $this->createStats($userId, $localDate);

		$hasOpenEnter = (bool)$stats['HAS_OPEN_ENTER'];
		[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);

		$updateFields = [
			'CNT' => $stats['CNT'] + $cntDelta,
			'HAS_OPEN_ENTER' => $newHasOpenEnter ? 1 : 0,
		];

		$isClosingLeave =
			$entityType !== CheckInType::MANUAL->value
			&& $description === self::DESCRIPTION_LEAVE
			&& $hasOpenEnter;

		if (!$isClosingLeave && $this->isNewerThanLast((int)($stats['LAST_CHECK_IN_ID'] ?? 0), $timestamp + $userTimezone))
		{
			$updateFields['LAST_CHECK_IN_ID'] = $checkInId;
		}

		CheckInDailyStatsTable::update($stats['ID'], $updateFields);
	}

	private function isNewerThanLast(int $lastCheckInId, int $localTimestamp): bool
	{
		if ($lastCheckInId <= 0)
		{
			return true;
		}

		$lastLocalTimestamp = $this->getCheckInLocalTimestamp($lastCheckInId);
		if ($lastLocalTimestamp === null)
		{
			return true;
		}

		return $localTimestamp > $lastLocalTimestamp;
	}

	private function getCheckInLocalTimestamp(int $checkInId): ?int
	{
		$row = CheckInTable::query()
			->setSelect(['DATE_CREATE', 'USER_TIMEZONE'])
			->where('ID', $checkInId)
			->setLimit(1)
			->exec()
			->fetch();

		if (!$row || !($row['DATE_CREATE'] instanceof DateTime))
		{
			return null;
		}

		return $row['DATE_CREATE']->getTimestamp() + (int)($row['USER_TIMEZONE'] ?? 0);
	}

	/**
	 * @return array{int, bool} [$cntDelta, $newHasOpenEnter]
	 */
	private function calculateDelta(int $entityType, string $description, bool $hasOpenEnter): array
	{
		if ($entityType === CheckInType::MANUAL->value)
		{
			$delta = $hasOpenEnter ? 2 : 1;

			return [$delta, false];
		}

		if ($description === self::DESCRIPTION_ENTER)
		{
			$delta = $hasOpenEnter ? 1 : 0;

			return [$delta, true];
		}

		if ($description === self::DESCRIPTION_LEAVE)
		{
			return [1, false];
		}

		$delta = $hasOpenEnter ? 2 : 1;

		return [$delta, false];
	}

	public function backFillForUser(int $userId, array $checkInRows, Date $localDate): void
	{
		if ($this->findStats($userId, $localDate) !== null)
		{
			return;
		}

		$cnt = 0;
		$hasOpenEnter = false;
		$lastCheckInId = 0;

		foreach ($checkInRows as $row)
		{
			$entityType = (int)($row['ENTITY_TYPE'] ?? 0);
			$description = (string)($row['DESCRIPTION'] ?? '');
			[$cntDelta, $newHasOpenEnter] = $this->calculateDelta($entityType, $description, $hasOpenEnter);
			$cnt += $cntDelta;

			$isClosingLeave =
				$entityType !== CheckInType::MANUAL->value
				&& $description === self::DESCRIPTION_LEAVE
				&& $hasOpenEnter;

			if (!$isClosingLeave)
			{
				$lastCheckInId = (int)($row['ID'] ?? 0);
			}

			$hasOpenEnter = $newHasOpenEnter;
		}

		CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => $cnt,
			'HAS_OPEN_ENTER' => $hasOpenEnter ? 1 : 0,
			'LAST_CHECK_IN_ID' => $lastCheckInId,
		]);
	}

	private function findStats(int $userId, Date $localDate): ?array
	{
		$row = CheckInDailyStatsTable::query()
			->setSelect(['ID', 'CNT', 'HAS_OPEN_ENTER', 'LAST_CHECK_IN_ID'])
			->where('USER_ID', $userId)
			->where('LOCAL_DATE', $localDate)
			->setLimit(1)
			->exec()
			->fetch();

		return $row ?: null;
	}

	private function createStats(int $userId, Date $localDate): array
	{
		$result = CheckInDailyStatsTable::add([
			'USER_ID' => $userId,
			'LOCAL_DATE' => $localDate,
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
			'LAST_CHECK_IN_ID' => 0,
		]);

		return [
			'ID' => $result->getId(),
			'CNT' => 0,
			'HAS_OPEN_ENTER' => 0,
		];
	}

	private function getLocalDate(int $timestamp, int $userTimezone): Date
	{
		$localDateString = date('Y-m-d', $timestamp + $userTimezone);

		return new Date($localDateString, 'Y-m-d');
	}
}
