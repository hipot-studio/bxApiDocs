<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\StaffTrack\Public\Services\CheckInMarkerAggregator;

class CheckInMarkerNormalizer
{
	/**
	 * @param array{kind: string, event: array, duration: ?int, observedDuration: ?int, auto: ?array, timestamp: int} $marker
	 * @param array<int, array> $rawRowsById
	 * @param array $files
	 * @param int $timezoneOffset
	 * @return array{id: int, kind: string, lat: ?float, lon: ?float, address: string, time: int, duration: ?int, observedDuration: ?int, description: string, files: array, auto: ?array, userId: int, entityType: int, dateCreate: string}
	 */
	public static function normalize(array $marker, array $rawRowsById, array $files, int $timezoneOffset): array
	{
		$event = $marker['event'];
		$leadingId = (int)($event['id'] ?? 0);
		$isStay = $marker['kind'] === CheckInMarkerAggregator::KIND_STAY;
		$rawRow = $rawRowsById[$leadingId] ?? self::rawRowFromEvent($event);

		$base = CheckInNormalizer::normalize($rawRow, $timezoneOffset, $isStay ? [] : $files);

		$reference = self::resolveReferencePoint($marker);
		$timestamp = (int)$marker['timestamp'];
		$referenceRow = $rawRowsById[(int)($reference['id'] ?? 0)] ?? $rawRow;

		return [
			'id' => (int)($reference['id'] ?? 0),
			'kind' => $marker['kind'],
			'lat' => isset($reference['latitude']) && $reference['latitude'] !== null ? (float)$reference['latitude'] : null,
			'lon' => isset($reference['longitude']) && $reference['longitude'] !== null ? (float)$reference['longitude'] : null,
			'address' => self::resolveAddress($reference, $event),
			'time' => $timestamp,
			'duration' => $marker['duration'],
			// How much of the duration the day still has records for. The field is newer than the shape
			// around it, so a marker put together outside buildMarkers() can arrive without it - unlike
			// duration a line above. Absent reads as null, never counted, not as nothing observed.
			'observedDuration' => $marker['observedDuration'] ?? null,
			'description' => $isStay ? '' : $base['description'],
			'files' => $base['files'],
			'auto' => self::normalizeAuto($marker['auto']),
			'userId' => $base['userId'],
			'entityType' => $base['entityType'],
			'dateCreate' => CheckInNormalizer::formatDateCreate($timestamp, $referenceRow, $timezoneOffset),
		];
	}

	/**
	 * The point a marker is drawn at. A merged visit is drawn where the tracker stood rather than where
	 * the employee pressed the button, so the coordinates of the card, of the pin on the map and of
	 * whoever counts the visits of the day all come from here and are never resolved a second time.
	 */
	public static function resolveReferencePoint(array $marker): array
	{
		if ($marker['kind'] === CheckInMarkerAggregator::KIND_MERGED && !empty($marker['auto']))
		{
			return $marker['auto'];
		}

		return $marker['event'];
	}

	private static function resolveAddress(array $reference, array $event): string
	{
		$address = (string)($reference['address'] ?? '');

		return $address === '' ? (string)($event['address'] ?? '') : $address;
	}

	/**
	 * @param ?array{id: int, timestamp: int, latitude: ?float, longitude: ?float, address: string} $auto
	 * @return ?array{id: int, time: int, lat: ?float, lon: ?float, address: string}
	 */
	private static function normalizeAuto(?array $auto): ?array
	{
		if ($auto === null)
		{
			return null;
		}

		return [
			'id' => (int)($auto['id'] ?? 0),
			'time' => (int)($auto['timestamp'] ?? 0),
			'lat' => isset($auto['latitude']) && $auto['latitude'] !== null ? (float)$auto['latitude'] : null,
			'lon' => isset($auto['longitude']) && $auto['longitude'] !== null ? (float)$auto['longitude'] : null,
			'address' => (string)($auto['address'] ?? ''),
		];
	}

	/**
	 * @param array $event
	 * @return array
	 */
	private static function rawRowFromEvent(array $event): array
	{
		return [
			'ID' => (int)($event['id'] ?? 0),
			'USER_ID' => (int)($event['userId'] ?? 0),
			'ENTITY_TYPE' => (int)($event['entityType'] ?? 0),
			'DESCRIPTION' => (string)($event['description'] ?? ''),
			'ADDRESS' => (string)($event['address'] ?? ''),
			'LATITUDE' => $event['latitude'] ?? null,
			'LONGITUDE' => $event['longitude'] ?? null,
			'DATE_CREATE' => (int)($event['timestamp'] ?? 0),
			'USER_TIMEZONE' => (int)($event['userTimezone'] ?? 0),
		];
	}
}
