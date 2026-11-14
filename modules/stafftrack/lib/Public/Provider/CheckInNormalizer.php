<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Text\Emoji;

class CheckInNormalizer
{
	private const MIN_ZONE_OFFSET = -12 * 3600;
	private const MAX_ZONE_OFFSET = 14 * 3600;

	/**
	 * @param array $data
	 * @param int $timezoneOffset
	 * @param array|null $resolvedFiles
	 * @return array
	 */
	public static function normalize(array $data, int $timezoneOffset = 0, ?array $resolvedFiles = []): array
	{
		$timestamp = self::extractTimestamp($data['DATE_CREATE'] ?? null);

		$hasGeo = self::hasGeo($data);

		return [
			'id' => (int)($data['ID'] ?? 0),
			'userId' => (int)$data['USER_ID'],
			'entityType' => (int)($data['ENTITY_TYPE'] ?? 1),
			'description' => Emoji::decode($data['DESCRIPTION'] ?? ''),
			'address' => $data['ADDRESS'] ?? '',
			'latitude' => $hasGeo ? (float)$data['LATITUDE'] : null,
			'longitude' => $hasGeo ? (float)$data['LONGITUDE'] : null,
			'files' => $resolvedFiles,
			'dateCreate' => self::formatDateCreate($timestamp, $data, $timezoneOffset),
		];
	}

	public static function formatDateCreate(int $timestamp, array $data, int $timezoneOffset = 0): string
	{
		if ($timestamp <= 0)
		{
			return '';
		}

		return date('Y-m-d H:i:s', $timestamp + (int)($data['USER_TIMEZONE'] ?? $timezoneOffset));
	}

	public static function zoneOffsetFromDateCreate(int $timestamp, ?string $dateCreate): ?int
	{
		if ($timestamp <= 0 || $dateCreate === null || $dateCreate === '')
		{
			return null;
		}

		$wallClock = strtotime($dateCreate . ' UTC');
		if ($wallClock === false)
		{
			return null;
		}

		$offset = $wallClock - $timestamp;

		return $offset >= self::MIN_ZONE_OFFSET && $offset <= self::MAX_ZONE_OFFSET ? $offset : null;
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public static function hasGeo(array $data): bool
	{
		return isset($data['LATITUDE'], $data['LONGITUDE'])
			&& $data['LATITUDE'] !== ''
			&& $data['LONGITUDE'] !== '';
	}

	private static function extractTimestamp(mixed $value): int
	{
		if ($value instanceof DateTime)
		{
			return $value->getTimestamp();
		}

		if (is_int($value))
		{
			return $value;
		}

		return 0;
	}
}
