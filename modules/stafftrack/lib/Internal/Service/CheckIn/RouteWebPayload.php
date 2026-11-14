<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\CheckIn;

final class RouteWebPayload
{
	private const COORDINATE_KEYS = ['lat', 'lon'];

	private const TRACK_KEYS = [
		'id',
		'lat',
		'lon',
		'time',
		'gapBefore',
		'address',
		'gapBeforeSec',
		'pausedBeforeSec',
	];

	private const MARKER_KEYS = [
		'id',
		'kind',
		'lat',
		'lon',
		'address',
		'time',
		'duration',
		'observedDuration',
		'description',
		'files',
		'auto',
		'dateCreate',
	];

	private const DAY_KEYS = [
		'isDayClosed',
		'serverNow',
		'isDayLive',
		'dayStartTimestamp',
		'dayCloseTimestamp',
		'headGapSec',
		'headPausedSec',
		'tailPausedSec',
		'tailPauseOpen',
		'durationsSuppressed',
	];

	/**
	 * @param array<string, mixed> $day
	 * @param array{id?: mixed, name?: mixed, workPosition?: mixed, avatar?: mixed} $employee
	 * @return array<string, mixed>
	 */
	public static function format(array $day, array $employee, string $pullTag): array
	{
		$payload = [
			'employee' => self::formatEmployee($employee),
			'pullTag' => $pullTag,
			'track' => self::formatPoints($day['track'] ?? [], self::TRACK_KEYS),
			'markers' => self::formatPoints($day['markers'] ?? [], self::MARKER_KEYS),
		];

		foreach (self::DAY_KEYS as $key)
		{
			if (array_key_exists($key, $day))
			{
				$payload[$key] = $day[$key];
			}
		}

		return $payload;
	}

	/**
	 * @param array{id?: mixed, name?: mixed, workPosition?: mixed, avatar?: mixed} $employee
	 * @return array{id: int, name: string, workPosition: string, avatar: ?string}
	 */
	private static function formatEmployee(array $employee): array
	{
		$avatar = (string)($employee['avatar'] ?? '');

		return [
			'id' => (int)($employee['id'] ?? 0),
			'name' => (string)($employee['name'] ?? ''),
			'workPosition' => (string)($employee['workPosition'] ?? ''),
			'avatar' => $avatar === '' ? null : $avatar,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $points
	 * @param string[] $allowedKeys
	 * @return array<int, array<string, mixed>>
	 */
	private static function formatPoints(array $points, array $allowedKeys): array
	{
		return array_map(
			static fn(array $point): array => self::formatPoint($point, $allowedKeys),
			array_values($points),
		);
	}

	/**
	 * @param array<string, mixed> $point
	 * @param string[] $allowedKeys
	 * @return array<string, mixed>
	 */
	private static function formatPoint(array $point, array $allowedKeys): array
	{
		$formatted = [];
		foreach ($allowedKeys as $key)
		{
			if (array_key_exists($key, $point))
			{
				$formatted[$key] = $point[$key];
			}
		}

		foreach (self::COORDINATE_KEYS as $key)
		{
			if (isset($formatted[$key]))
			{
				$formatted[$key] = (float)$formatted[$key];
			}
		}

		return $formatted;
	}
}
