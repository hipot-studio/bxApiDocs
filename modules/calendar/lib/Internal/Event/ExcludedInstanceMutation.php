<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use Bitrix\Calendar\Rooms\Util;

/**
 * @phpstan-type LegacyEvent array{
 *   ID: int|string,
 *   DATE_FROM: string,
 *   DATE_TO: string,
 *   EXDATE?: string|null,
 *   PARENT_ID?: int|string|null,
 *   SECTION_ID?: int|string|null,
 *   SECTIONS?: array<int, int|string>,
 *   OWNER_ID?: int|string|null,
 *   CAL_TYPE?: string|null,
 *   DT_SKIP_TIME?: string|null,
 *   TZ_FROM?: string|null,
 *   TZ_TO?: string|null,
 *   RRULE?: string|array<string, mixed>|null,
 *   LOCATION?: string|array{NEW?: string|null, OLD?: string|null}|null,
 *   ATTENDEES?: array<int, int|string>|null,
 *   ATTENDEE_LIST?: array<int, array{id: int|string}>
 * }
 */
class ExcludedInstanceMutation
{
	public static function requiresOccupancyCheck(array $params, array $fields, array $currentEvent): bool
	{
		$context = $params['excludedInstanceContext'] ?? null;
		if (!$context instanceof self)
		{
			return true;
		}
		if (!$context->permits($fields, $currentEvent))
		{
			throw new \RuntimeException('The excluded instance no longer matches the series.');
		}

		return false;
	}

	public static function validateChildSave(array $params, mixed $id): void
	{
		if (!$id && ($params['excludedInstanceContext'] ?? null) instanceof self)
		{
			throw new \RuntimeException('Unable to save the excluded instance for an attendee.');
		}
	}

	public static function allowsEditMail(array $params): bool
	{
		return !(($params['excludedInstanceContext'] ?? null) instanceof self);
	}

	/** @param LegacyEvent $event */
	public function __construct(
		public readonly int $eventId,
		public readonly string $date,
		private readonly array $event,
	)
	{
	}

	/**
	 * @param LegacyEvent $fields
	 * @param LegacyEvent $currentEvent
	 */
	public function permits(array $fields, array $currentEvent): bool
	{
		if (
			(int)($fields['ID'] ?? 0) !== $this->eventId
			|| (int)($currentEvent['ID'] ?? 0) !== $this->eventId
			|| $this->occupancyFields($fields) !== $this->occupancyFields($this->event)
			|| $this->occupancyFields($currentEvent) !== $this->occupancyFields($this->event)
			|| $this->dates($currentEvent['EXDATE'] ?? '') !== $this->dates($this->event['EXDATE'] ?? '')
		)
		{
			return false;
		}

		$expectedDates = $this->dates($this->event['EXDATE'] ?? '');
		$expectedDates[] = $this->date;
		$expectedDates = array_values(array_unique($expectedDates));
		sort($expectedDates, SORT_STRING);

		return $this->dates($fields['EXDATE'] ?? '') === $expectedDates;
	}

	/** @param LegacyEvent $event */
	private function occupancyFields(array $event): array
	{
		$location = $event['LOCATION'] ?? '';
		if (is_array($location))
		{
			$location = $location['NEW'] ?? '';
		}
		$roomId = (int)(Util::parseLocation($location)['room_id'] ?? 0);
		$rule = \CCalendarEvent::ParseRRULE($event['RRULE'] ?? '');
		unset($rule['UNTIL_TS'], $rule['~UNTIL']);
		$rule['UNTIL'] ??= \CCalendar::GetMaxDate();
		if (isset($rule['BYDAY']))
		{
			$rule['BYDAY'] = \CCalendar::normalizeRRuleByDay($rule['BYDAY']);
			ksort($rule['BYDAY']);
		}
		ksort($rule);
		$attendees = $event['ATTENDEES'] ?? array_column($event['ATTENDEE_LIST'] ?? [], 'id');
		$attendees = array_values(array_unique(array_filter(array_map('intval', $attendees), static fn (int $id): bool => $id > 0)));
		sort($attendees, SORT_NUMERIC);

		return [
			'parentId' => (int)($event['PARENT_ID'] ?? $event['ID'] ?? 0),
			'sectionId' => (int)($event['SECTION_ID'] ?? $event['SECTIONS'][0] ?? 0),
			'ownerId' => (int)($event['OWNER_ID'] ?? 0),
			'type' => $event['CAL_TYPE'] ?? '',
			'from' => \CCalendar::Timestamp($event['DATE_FROM'], false, ($event['DT_SKIP_TIME'] ?? 'N') !== 'Y'),
			'to' => \CCalendar::Timestamp($event['DATE_TO'], false, ($event['DT_SKIP_TIME'] ?? 'N') !== 'Y'),
			'timezoneFrom' => $event['TZ_FROM'] ?? '',
			'timezoneTo' => $event['TZ_TO'] ?? '',
			'allDay' => $event['DT_SKIP_TIME'] ?? 'N',
			'rule' => $rule,
			'location' => $roomId > 0 ? $roomId : $location,
			'attendees' => $attendees,
		];
	}

	private function dates(string $dates): array
	{
		$result = array_values(array_unique(\CCalendarEvent::GetExDate($dates)));
		sort($result, SORT_STRING);

		return $result;
	}
}
