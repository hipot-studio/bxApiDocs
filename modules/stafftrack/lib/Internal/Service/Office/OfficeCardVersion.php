<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\StaffTrack\Internal\Model\Office;

/**
 * The version of an office card: an opaque fingerprint of the stored fields a person edits. Photos, the
 * staff, the authors, the dates and the map snapshot are not part of it - they change without an edit
 * of the card.
 *
 * Coordinates are compared in one canonical form both for the fingerprint and for the change of the zone,
 * so a float read back from the storage never differs from itself.
 */
final class OfficeCardVersion
{
	/** About a centimetre, fixed-point and independent of the locale. */
	private const COORD_FORMAT = '%.7F';

	public static function versionOf(Office $office): string
	{
		return self::versionOfCard([
			'TITLE' => $office->getTitle(),
			'LATITUDE' => $office->getLatitude(),
			'LONGITUDE' => $office->getLongitude(),
			'GEO_RADIUS' => $office->getGeoRadius(),
			'ADDRESS' => $office->getAddress(),
			'WORK_TIME_FROM' => $office->getWorkTimeFrom(),
			'WORK_TIME_TO' => $office->getWorkTimeTo(),
		]);
	}

	/**
	 * The version the card would have once written: it tells whether a card sent again is the one already
	 * stored, and is never compared with the version a caller names.
	 *
	 * @param array $cardFields The fields of the card keyed as the storage keeps them.
	 */
	public static function versionOfCard(array $cardFields): string
	{
		$workTimeFrom = $cardFields['WORK_TIME_FROM'] ?? null;
		$workTimeTo = $cardFields['WORK_TIME_TO'] ?? null;

		$parts = [
			(string)($cardFields['TITLE'] ?? ''),
			self::canonicalCoord($cardFields['LATITUDE'] ?? 0),
			self::canonicalCoord($cardFields['LONGITUDE'] ?? 0),
			(string)(int)($cardFields['GEO_RADIUS'] ?? 0),
			(string)($cardFields['ADDRESS'] ?? ''),
			$workTimeFrom === null ? '' : (string)(int)$workTimeFrom,
			$workTimeTo === null ? '' : (string)(int)$workTimeTo,
		];

		return md5(json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * The point is moved or the radius is made smaller: someone confirmed inside the old zone may be
	 * outside the new one.
	 */
	public static function isZoneShrunkOrMoved(Office $office, float $latitude, float $longitude, int $geoRadius): bool
	{
		$moved = self::canonicalCoord($office->getLatitude()) !== self::canonicalCoord($latitude)
			|| self::canonicalCoord($office->getLongitude()) !== self::canonicalCoord($longitude)
		;

		return $moved || $geoRadius < (int)$office->getGeoRadius();
	}

	public static function isZoneChanged(Office $office, float $latitude, float $longitude, int $geoRadius): bool
	{
		return self::isZoneShrunkOrMoved($office, $latitude, $longitude, $geoRadius)
			|| $geoRadius !== (int)$office->getGeoRadius()
		;
	}

	public static function isSamePoint(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): bool
	{
		return self::canonicalCoord($latitudeA) === self::canonicalCoord($latitudeB)
			&& self::canonicalCoord($longitudeA) === self::canonicalCoord($longitudeB)
		;
	}

	private static function canonicalCoord(mixed $value): string
	{
		return sprintf(self::COORD_FORMAT, (float)$value);
	}
}
