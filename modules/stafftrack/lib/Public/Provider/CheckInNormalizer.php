<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\Main\Text\Emoji;

class CheckInNormalizer
{
	/**
	 * @param array $data
	 * @param int $timezoneOffset
	 * @param bool $shouldGetUser
	 * @param array|null $resolvedFiles
	 * @return array
	 */
	public static function normalize(array $data, int $timezoneOffset = 0, ?bool $shouldGetUser = true, ?array $resolvedFiles = []): array
	{
		$timestamp = self::extractTimestamp($data['DATE_CREATE'] ?? null);

		$userId = (int)$data['USER_ID'];
		$checkInId = (int)($data['ID'] ?? 0);

		$result = [
			'id' => $checkInId,
			'userId' => $userId,
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

		if ($shouldGetUser)
		{
			$result['user'] = UserRepository::getByIds([$userId]);
		}

		return $result;
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
