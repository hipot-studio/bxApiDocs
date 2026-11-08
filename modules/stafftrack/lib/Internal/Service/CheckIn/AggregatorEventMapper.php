<?php

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

use Bitrix\Main\Type\DateTime;

/**
 * Rows of a day in the shape CheckInMarkerAggregator::buildMarkers() reads them by. Every reader of the
 * markers of a day goes through here, so the aggregator is told one and the same story about a point
 * whoever raised it.
 */
class AggregatorEventMapper
{
	/**
	 * @param array<int, array> $rows raw and expanded aggregated rows of the day, in the order they are read
	 * @return array<int, array{id: int, entityType: int, description: string, latitude: ?float, longitude: ?float, address: string, timestamp: int, userTimezone: int}>
	 */
	public static function fromRows(array $rows): array
	{
		$events = [];
		foreach ($rows as $row)
		{
			$dateCreate = $row['DATE_CREATE'] ?? null;
			$events[] = [
				'id' => (int)($row['ID'] ?? 0),
				'entityType' => (int)($row['ENTITY_TYPE'] ?? 0),
				'description' => (string)($row['DESCRIPTION'] ?? ''),
				'latitude' => ($row['LATITUDE'] ?? null) !== null ? (float)$row['LATITUDE'] : null,
				'longitude' => ($row['LONGITUDE'] ?? null) !== null ? (float)$row['LONGITUDE'] : null,
				'address' => (string)($row['ADDRESS'] ?? ''),
				'timestamp' => $dateCreate instanceof DateTime ? $dateCreate->getTimestamp() : (int)$dateCreate,
				'userTimezone' => (int)($row['USER_TIMEZONE'] ?? 0),
			];
		}

		return $events;
	}
}
