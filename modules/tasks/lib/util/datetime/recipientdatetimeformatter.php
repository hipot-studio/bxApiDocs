<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Util\DateTime;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Util\UI;

final class RecipientDateTimeFormatter
{
	/** @var array<int, array{timeZone: string, legacyOffset: ?int}|null> */
	private static array $userTimeZoneCache = [];

	public static function formatForRecipient(
		int $timestamp,
		int $recipientUserId,
		string $languageId,
		?string $format = null,
		bool $force = true,
	): string
	{
		return self::formatManyForRecipient(
			[$timestamp],
			$recipientUserId,
			$languageId,
			$format,
			$force,
		)[0];
	}

	public static function formatTimeForRecipient(
		int $timestamp,
		int $recipientUserId,
		string $languageId,
		bool $force = true,
	): string
	{
		return self::formatMany(
			[$timestamp],
			$recipientUserId,
			$languageId,
			null,
			$force,
			static fn(int $recipientTimestamp): string => UI::getHumanTimeFormat($recipientTimestamp),
		)[0];
	}

	/**
	 * @param int[] $timestamps
	 * @return string[]
	 */
	public static function formatManyForRecipient(
		array $timestamps,
		int $recipientUserId,
		string $languageId,
		?string $format = null,
		bool $force = true,
	): array
	{
		return self::formatMany(
			$timestamps,
			$recipientUserId,
			$languageId,
			$format,
			$force,
			static fn(int $recipientTimestamp): string => UI::getHumanDateTimeFormat($recipientTimestamp),
		);
	}

	/**
	 * @param int[] $timestamps
	 * @param callable(int): string $defaultFormatResolver
	 * @return string[]
	 */
	private static function formatMany(
		array $timestamps,
		int $recipientUserId,
		string $languageId,
		?string $format,
		bool $force,
		callable $defaultFormatResolver,
	): array
	{
		$timeZoneData = null;
		$timeZoneDataResolved = false;
		$result = [];

		foreach ($timestamps as $key => $timestamp)
		{
			if ($timestamp <= 0)
			{
				$result[$key] = '';
				continue;
			}

			if (!$timeZoneDataResolved)
			{
				$timeZoneData = self::getRecipientTimeZoneData($recipientUserId, $force);
				$timeZoneDataResolved = true;
			}

			$deadlineDate = DateTime::createFromTimestamp($timestamp);
			$offset = self::getOffset($recipientUserId, $deadlineDate, $timeZoneData, $force);
			$recipientTimestamp = self::toFormatTimestamp($timestamp, $offset);
			$dateTimeFormat = $format ?? $defaultFormatResolver($recipientTimestamp);

			$result[$key] = \FormatDate($dateTimeFormat, $recipientTimestamp, false, $languageId);
		}

		return $result;
	}

	/**
	 * Builds a timestamp that FormatDate (server timezone) renders as the recipient wall clock.
	 * Unlike `$timestamp + $offset`, this stays correct across server DST transitions.
	 *
	 * Known limitation: mktime() interprets wall-clock components in the server timezone.
	 * If the recipient local time falls into a server DST "skipped" hour (spring forward),
	 * PHP normalizes it forward by one hour. Narrow window — about one hour per year on
	 * servers that observe DST. Closing it would require formatting outside the server zone
	 * (e.g. temporary UTC around FormatDate).
	 */
	private static function toFormatTimestamp(int $timestamp, int $relativeOffset): int
	{
		$serverOffset = DateTime::createFromTimestamp($timestamp)->getOffset();
		$wallAsUtc = $timestamp + $serverOffset + $relativeOffset;

		$formatTimestamp = mktime(
			(int)gmdate('G', $wallAsUtc),
			(int)gmdate('i', $wallAsUtc),
			(int)gmdate('s', $wallAsUtc),
			(int)gmdate('n', $wallAsUtc),
			(int)gmdate('j', $wallAsUtc),
			(int)gmdate('Y', $wallAsUtc),
		);

		return $formatTimestamp === false ? $timestamp + $relativeOffset : $formatTimestamp;
	}

	private static function getRecipientTimeZoneData(int $recipientUserId, bool $force): ?array
	{
		global $USER;

		$isCurrentUser = is_object($USER) && (int)$USER->GetID() === $recipientUserId;
		$timeZoneEnabled = $force ? \CTimeZone::OptionEnabled() : \CTimeZone::Enabled();
		if (!$timeZoneEnabled || $isCurrentUser)
		{
			return null;
		}

		if (array_key_exists($recipientUserId, self::$userTimeZoneCache))
		{
			return self::$userTimeZoneCache[$recipientUserId];
		}

		$dbUser = \CUser::GetList(
			'id',
			'asc',
			['ID_EQUAL_EXACT' => $recipientUserId],
			['FIELDS' => ['TIME_ZONE', 'TIME_ZONE_OFFSET']],
		);
		$user = $dbUser->Fetch();
		if ($user === false)
		{
			self::$userTimeZoneCache[$recipientUserId] = [
				'timeZone' => '',
				'legacyOffset' => null,
			];

			return self::$userTimeZoneCache[$recipientUserId];
		}

		$timeZone = empty($user['TIME_ZONE']) ? '' : (string)$user['TIME_ZONE'];

		self::$userTimeZoneCache[$recipientUserId] = [
			'timeZone' => $timeZone,
			'legacyOffset' => $timeZone === '' ? (int)$user['TIME_ZONE_OFFSET'] : null,
		];

		return self::$userTimeZoneCache[$recipientUserId];
	}

	private static function getOffset(
		int $recipientUserId,
		DateTime $date,
		?array $timeZoneData,
		bool $force,
	): int
	{
		if ($timeZoneData === null)
		{
			return \CTimeZone::GetOffset($recipientUserId, $force, $date);
		}

		if ($timeZoneData['legacyOffset'] !== null)
		{
			return $timeZoneData['legacyOffset'];
		}

		return \CTimeZone::getTimezoneOffset($timeZoneData['timeZone'], $date);
	}
}
