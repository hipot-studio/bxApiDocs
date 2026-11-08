<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Service\CheckIn\AggregatorEventMapper;
use Bitrix\StaffTrack\Internal\Service\CheckIn\CheckInDayRowsReader;
use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;

/**
 * The route of an employee summarized for a window the caller names. Unlike the day read of the map and
 * the timeline, this one has no viewer at all: it is built for an employee by whoever happens to run the
 * report, so nothing here reads the current user, subscribes anybody to updates or resolves attachments.
 */
class CheckInDaySummaryProvider
{
	private CheckInDayRowsReader $rowsReader;

	public function __construct()
	{
		$this->rowsReader = new CheckInDayRowsReader();
	}

	/**
	 * @param int $userId employee whose route is summarized
	 * @param int $fromTimestamp server timestamp the window opens at
	 * @param int $toTimestamp server timestamp the window closes at
	 * @param int $limit how many points the caller will show, 0 for all of them
	 * @return array{points: array<int, array{id: int, time: int, address: string, durationSec: ?int}>, timezoneOffset: int, hasMore: bool}
	 */
	public function getRouteSummary(int $userId, int $fromTimestamp, int $toTimestamp, int $limit = 0): array
	{
		// A window that never opened is not a window over the whole history: an empty start reaches here as
		// zero, and read as a moment it would take in every point the employee has ever left, and scan his
		// whole table to find them.
		if (
			$userId <= 0
			|| $fromTimestamp <= 0
			|| $toTimestamp < $fromTimestamp
			|| !Feature::isCheckInRolloutEnabled($userId)
		)
		{
			return self::emptySummary();
		}

		$rows = $this->rowsReader->readWindow(
			$userId,
			DateTime::createFromTimestamp($fromTimestamp),
			DateTime::createFromTimestamp($toTimestamp),
		);
		if ($rows === [])
		{
			return self::emptySummary();
		}

		// The day of the employee is read in his own offset: the report is written for him, and the hours
		// of whoever runs it say nothing about when his day happened.
		$timezoneOffset = CheckInDayRowsReader::resolveTimezoneOffset($rows, $userId);
		$points = $this->buildPoints($rows, $fromTimestamp, $toTimestamp, $timezoneOffset);

		$hasMore = $limit > 0 && count($points) > $limit;

		return [
			'points' => $hasMore ? array_slice($points, 0, $limit) : $points,
			'timezoneOffset' => $timezoneOffset,
			'hasMore' => $hasMore,
		];
	}

	/**
	 * @param array<int, array> $rows
	 * @return array<int, array{id: int, time: int, address: string, durationSec: ?int}>
	 */
	private function buildPoints(array $rows, int $fromTimestamp, int $toTimestamp, int $timezoneOffset): array
	{
		// Without geo encryption the address comes back as the ciphertext it was stored as: the field is
		// declared with encryption off, so nothing decrypts it on the read. Reported as it is, it would
		// carry encrypted geo data into the message and into the record of the day. The point stays - a
		// place with no name for it is still a place the employee has been.
		$addressesAreReadable = Feature::isGeoEncryptionAvailable();

		$rawRowsById = [];
		foreach ($rows as $row)
		{
			$rawRowsById[(int)($row['ID'] ?? 0)] = $row;
		}

		// A stay the window ends in the middle of is closed by the window: read without that moment it
		// would go on growing towards now every time the report of a day long over is rebuilt.
		$markers = (new CheckInMarkerAggregator())->buildMarkers(
			AggregatorEventMapper::fromRows($rows),
			$toTimestamp,
		);

		$points = [];
		foreach ($markers as $marker)
		{
			$point = CheckInMarkerNormalizer::normalize($marker, $rawRowsById, [], $timezoneOffset);
			$time = (int)$point['time'];
			if ($time < $fromTimestamp || $time > $toTimestamp)
			{
				continue;
			}

			$points[] = [
				'id' => (int)$point['id'],
				'time' => $time,
				'address' => $addressesAreReadable ? (string)$point['address'] : '',
				'durationSec' => $point['duration'] === null ? null : (int)$point['duration'],
			];
		}

		return $points;
	}

	/**
	 * @return array{points: array, timezoneOffset: int, hasMore: bool}
	 */
	private static function emptySummary(): array
	{
		return [
			'points' => [],
			'timezoneOffset' => 0,
			'hasMore' => false,
		];
	}
}
