<?php

namespace Bitrix\Mobile\Internal\Onboarding\Config;

use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesRhythm;

/**
 * Values of the `schedule` section of the campaign document: the send window, the
 * portal age gate, the rhythm and the day offsets of the series and the calendar rules
 * applied to them.
 *
 * Parsing fails closed - a missing or malformed value degrades to the option that
 * sends nothing rather than to a guess: no window (windowHours = 0), an age gate no
 * portal can satisfy (-1), no send days at all, no rhythm the series could run on.
 *
 * Offsets are clamped, because they come from a document the engine does not own and
 * every one of them is walked day by day for every user of the portal.
 */
final readonly class ScheduleConfig
{
	public const MAX_SEND_DAYS = 10;
	public const MAX_OFFSET_DAYS = 60;

	public const TIMEZONE_PORTAL = 'portal';
	public const TIMEZONE_SERVER = 'server';

	/**
	 * The zones a window may be declared in. Parsing reads anything other than the portal one
	 * as the server zone, so the vocabulary is named here rather than left to that comparison:
	 * a document naming a zone of its own means a window somewhere else entirely, and it is
	 * refused at acceptance instead of quietly becoming the server one.
	 */
	public const TIMEZONES = [self::TIMEZONE_PORTAL, self::TIMEZONE_SERVER];

	/**
	 * @param int[] $sendDayOffsets
	 */
	private function __construct(
		public string $sendWindowStart,
		public int $sendWindowHours,
		public bool $isPortalTimezoneWindow,
		public int $maxPortalAgeDays,
		public array $sendDayOffsets,
		public ?SeriesRhythm $seriesRhythm,
		public bool $workdaysOnly,
		public bool $shiftWeekendToMonday,
		public bool $dedupPerDay,
	)
	{}

	/**
	 * @param array<string, mixed> $schedule the `schedule` section of the campaign document
	 */
	public static function fromSchedule(array $schedule): self
	{
		$window = is_array($schedule['sendWindow'] ?? null) ? $schedule['sendWindow'] : [];
		$maxPortalAgeDays = $schedule['maxPortalAgeDays'] ?? null;

		return new self(
			(string)($window['start'] ?? ''),
			(int)($window['windowHours'] ?? 0),
			($window['timezone'] ?? self::TIMEZONE_PORTAL) === self::TIMEZONE_PORTAL,
			is_numeric($maxPortalAgeDays) ? (int)$maxPortalAgeDays : -1,
			self::parseSendDayOffsets($schedule['sendDayOffsets'] ?? null),
			SeriesRhythm::fromNode($schedule['repeat'] ?? null),
			(bool)($schedule['workdaysOnly'] ?? true),
			(bool)($schedule['shiftWeekendToMonday'] ?? false),
			(bool)($schedule['dedupPerDay'] ?? true),
		);
	}

	/**
	 * @return int[]
	 */
	private static function parseSendDayOffsets(mixed $offsets): array
	{
		if (!is_array($offsets))
		{
			return [];
		}

		$parsed = array_map(
			static fn (mixed $offset): int => min(max((int)$offset, 0), self::MAX_OFFSET_DAYS),
			array_values(array_filter($offsets, 'is_numeric')),
		);

		return array_slice($parsed, 0, self::MAX_SEND_DAYS);
	}
}
