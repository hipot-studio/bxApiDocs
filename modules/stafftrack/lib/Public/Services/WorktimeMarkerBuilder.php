<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;

/**
 * The start and the end of the work day as markers of the ribbon (presence.route.v1).
 *
 * They have no check-in row, id or point, so they are not passed through the check-in marker aggregator and
 * are joined to its answer in the shape of CheckInMarkerNormalizer::normalize(). Their `dateCreate` carries
 * the offset of the day: the client reads the zone of the day off the markers only.
 */
class WorktimeMarkerBuilder
{
	public const KIND = 'worktime';

	public const SUB_KIND_DAY_START = 'day_start';
	public const SUB_KIND_DAY_END = 'day_end';

	/**
	 * A missing time, whether not reached yet or withheld from the reader, gives no marker.
	 *
	 * @param int $timezoneOffset the offset the day of this answer is read in
	 * @return array<int, array{
	 *     id: null,
	 *     kind: string,
	 *     subKind: string,
	 *     time: int,
	 *     dateCreate: string,
	 *     lat: null,
	 *     lon: null,
	 *     address: null,
	 * }>
	 */
	public static function build(
		?int $dayStartTimestamp,
		?int $dayCloseTimestamp,
		int $timezoneOffset
	): array
	{
		$markers = [];

		if ($dayStartTimestamp !== null)
		{
			$markers[] = self::marker(self::SUB_KIND_DAY_START, $dayStartTimestamp, $timezoneOffset);
		}

		if ($dayCloseTimestamp !== null)
		{
			$markers[] = self::marker(self::SUB_KIND_DAY_END, $dayCloseTimestamp, $timezoneOffset);
		}

		return $markers;
	}

	/**
	 * @return array{
	 *     id: null,
	 *     kind: string,
	 *     subKind: string,
	 *     time: int,
	 *     dateCreate: string,
	 *     lat: null,
	 *     lon: null,
	 *     address: null,
	 * }
	 */
	private static function marker(string $subKind, int $timestamp, int $timezoneOffset): array
	{
		return [
			// no check-in row stands behind the marker, and zero would read as an id
			'id' => null,
			'kind' => self::KIND,
			'subKind' => $subKind,
			'time' => $timestamp,
			// written as for a check-in: the client reads the zone off `time` and this string
			'dateCreate' => CheckInNormalizer::formatDateCreate($timestamp, [], $timezoneOffset),
			'lat' => null,
			'lon' => null,
			'address' => null,
		];
	}
}
