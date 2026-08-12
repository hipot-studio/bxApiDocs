<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Text\Emoji;

class CheckInNormalizer
{
	/**
	 * @param array $data
	 * @param int $timezoneOffset
	 * @param array|null $resolvedFiles
	 * @return array
	 */
	public static function normalize(array $data, int $timezoneOffset = 0, ?array $resolvedFiles = []): array
	{
		$timestamp = self::extractTimestamp($data['DATE_CREATE'] ?? null);

		return [
			'id' => (int)($data['ID'] ?? 0),
			'userId' => (int)$data['USER_ID'],
			'entityType' => (int)($data['ENTITY_TYPE'] ?? 1),
			'description' => Emoji::decode($data['DESCRIPTION'] ?? ''),
			'address' => $data['ADDRESS'] ?? '',
			'latitude' => (float)($data['LATITUDE'] ?? 0),
			'longitude' => (float)($data['LONGITUDE'] ?? 0),
			'files' => $resolvedFiles,
			'dateCreate' => $timestamp > 0
				? date('Y-m-d H:i:s', $timestamp + (int)($data['USER_TIMEZONE'] ?? $timezoneOffset))
				: '',
		];
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
