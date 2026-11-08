<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Provider;

use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar\Dto\GroupMeetingDto;

class GroupEventProvider
{
	public const FINISHED_DEFAULT_WINDOW_SECONDS = 30 * 86400;

	/**
	 * The widest period one read may cover; a wider request is clamped to this many seconds before
	 * its upper bound, so the newest meetings of the period are the ones examined.
	 *
	 * Calendar expands recurrence in PHP, so the cost of a read is sources × occurrences per source
	 * and both factors have to stay bounded. The densest series a calendar can express repeats
	 * daily, so within this window no series reaches FINISHED_MAX_INSTANCES_PER_SERIES: that cap can
	 * never truncate a series, and every occurrence of the scanned period takes part in the ordering
	 * behind the newest-first contract.
	 */
	private const FINISHED_MAX_WINDOW_SECONDS = 31 * 86400;

	// Ordinary events and recurring series have independent source budgets. A busy stream of recent
	// one-off events therefore cannot displace an older series before Calendar expands it.
	private const FINISHED_ORDINARY_SOURCE_SCAN_LIMIT = 500;
	private const FINISHED_RECURRING_SOURCE_SCAN_LIMIT = 100;
	// Safety net only — FINISHED_MAX_WINDOW_SECONDS keeps every series well below it.
	private const FINISHED_MAX_INSTANCES_PER_SERIES = 100;
	private const CALENDAR_LANG_FILE = '/modules/calendar/classes/general/calendar.php';

	/**
	 * @return array [eventId => chatId]
	 * @throws LoaderException
	 */
	public function getRecentlyActiveEvents(
		int $groupId,
		int $limit = 50,
	): array
	{
		return $this->fetchEvents(
			groupId: $groupId,
			order: ['TIMESTAMP_X' => 'DESC'],
			limit: $limit,
		);
	}

	public function getEventsPaged(
		int $groupId,
		int $limit = 50,
		int $lastId = 0,
	): array
	{
		return $this->fetchEvents(
			groupId: $groupId,
			order: ['ID' => 'DESC'],
			limit: $limit,
			lastId: $lastId,
		);
	}

	/**
	 * Returns finished group meetings sorted by meeting start date, newest first.
	 * Finished means the event has already ended. The from/to window is applied to the meeting
	 * start date, bounds are inclusive; an omitted lower bound falls back to
	 * FINISHED_DEFAULT_WINDOW_SECONDS before now, so the read is always bounded.
	 *
	 * Events are read on behalf of $userId with the calendar permission check on, so only
	 * meetings this user may see the title of are returned.
	 *
	 * @return list<GroupMeetingDto>
	 * @throws LoaderException
	 */
	public function getFinishedProjectMeetings(
		int $groupId,
		int $userId,
		?DateTime $from = null,
		?DateTime $to = null,
		int $limit = 10,
	): array
	{
		if ($limit <= 0 || $userId <= 0 || !Loader::includeModule('calendar'))
		{
			return [];
		}

		$now = time();
		$fromTs = $from?->getTimestamp() ?? ($now - self::FINISHED_DEFAULT_WINDOW_SECONDS);
		$toTs = min($to?->getTimestamp() ?? $now, $now);
		if ($fromTs > $toTs)
		{
			return [];
		}

		// Narrowing keeps the newest end of the requested period: the caller asks for the latest
		// meetings, and nothing outside the original bounds is ever returned.
		$fromTs = max($fromTs, $toTs - self::FINISHED_MAX_WINDOW_SECONDS);

		$filter = $this->getBaseFilter($groupId);
		$filter['FROM_LIMIT'] = \CCalendar::Date($fromTs, false);
		$filter['TO_LIMIT'] = \CCalendar::Date($toTs, false);

		$ordinaryEvents = $this->queryEvents(
			filter: $filter,
			order: ['DATE_FROM_TS_UTC' => 'DESC', 'ID' => 'DESC'],
			limit: self::FINISHED_ORDINARY_SOURCE_SCAN_LIMIT,
			userId: $userId,
			parseRecursion: false,
			recurrenceFilter: false,
		);
		$recurringEvents = $this->queryEvents(
			filter: $filter,
			order: ['DATE_FROM_TS_UTC' => 'DESC', 'ID' => 'DESC'],
			limit: self::FINISHED_RECURRING_SOURCE_SCAN_LIMIT,
			userId: $userId,
			parseRecursion: true,
			recurrenceFilter: true,
		);

		$ordinaryMeetings = $this->mapFinishedMeetings($ordinaryEvents, $now, $fromTs, $toTs);
		$recurringMeetings = array_values(array_filter(
			$this->attachPreviousRecurrence(
				$this->mapFinishedMeetings($recurringEvents, $now, null, $toTs, true),
			),
			static fn (GroupMeetingDto $meeting): bool => $meeting->date->getTimestamp() >= $fromTs,
		));

		return $this->mergeNewestMeetings($ordinaryMeetings, $recurringMeetings, $limit);
	}

	/**
	 * @return array<int, int|null>  [eventId => ?chatId]
	 * @throws LoaderException
	 */
	private function fetchEvents(
		int $groupId,
		array $order,
		int $limit,
		int $lastId = 0,
	): array
	{
		if (!Loader::includeModule('calendar'))
		{
			return [];
		}

		$filter = $this->getBaseFilter($groupId);

		if ($lastId > 0)
		{
			$filter['<ID'] = $lastId;
		}

		// Background agent path: the [eventId => chatId] map is built with no acting user,
		// so the calendar permission check stays off here (userId: null).
		return $this->formatEvents($this->queryEvents($filter, $order, $limit, null, false));
	}

	private function getBaseFilter(int $groupId): array
	{
		return [
			'CAL_TYPE'       => 'group',
			'OWNER_ID'       => $groupId,
			'DELETED'        => 'N',
			'ACTIVE_SECTION' => 'Y',
		];
	}

	/**
	 * $userId is the acting user the events are read for; null means "no acting user", which is
	 * the only case where the calendar permission check is skipped (background agent path).
	 *
	 * fetchAttendees stays off, so ATTENDEE_LIST is empty and the attendee shortcut inside GetList
	 * never fires: an attendee who is not a member of the project sees no meetings. This is
	 * fail-closed, and keeping the page projection unchanged keeps its cost bounded.
	 *
	 * User fields are never read from the result, so the join for them is turned off. The explicit
	 * projection reuses Calendar's ACL and recurrence-safe default and adds MEETING for CHAT_ID.
	 */
	private function queryEvents(
		array $filter,
		array $order,
		int $limit,
		?int $userId,
		bool $parseRecursion,
		?bool $recurrenceFilter = null,
	): array
	{
		$getListParams = [
			'arFilter'         => $filter,
			'arSelect'         => array_merge(\CCalendarEvent::$defaultSelectEvent, ['MEETING']),
			'parseDescription' => false,
			'parseRecursion'   => $parseRecursion,
			'fetchAttendees'   => false,
			'getUserfields'    => false,
			'checkPermissions' => $userId !== null,
			'setDefaultLimit'  => false,
		];

		if ($parseRecursion)
		{
			$getListParams['preciseLimits'] = true;
			$getListParams['maxInstanceCount'] = self::FINISHED_MAX_INSTANCES_PER_SERIES;
			$getListParams['includePreviousRecurrence'] = true;
		}
		if ($recurrenceFilter !== null)
		{
			$getListParams['recurrenceFilter'] = $recurrenceFilter;
		}

		if ($userId !== null)
		{
			$getListParams['userId'] = $userId;
		}

		if (!empty($order))
		{
			$getListParams['arOrder'] = $order;
		}

		if ($limit > 0)
		{
			$getListParams['limit'] = $limit;
		}

		$rawEvents = $this->getEventList($getListParams);

		return is_array($rawEvents) ? $rawEvents : [];
	}

	protected function getEventList(array $params): mixed
	{
		return \CCalendarEvent::GetList($params);
	}

	/**
	 * @return list<GroupMeetingDto>
	 */
	private function mapFinishedMeetings(
		array $rawEvents,
		int $now,
		?int $fromTs,
		int $toTs,
		bool $isRecurringSource = false,
	): array
	{
		$meetings = [];

		foreach ($rawEvents as $event)
		{
			$meetingData = $this->getFinishedMeetingData($event, $now, $fromTs, $toTs);
			if ($meetingData === null)
			{
				continue;
			}

			$name = $this->stripAccessibilityLabel($event, (string)($event['NAME'] ?? ''));

			$meetings[] = new GroupMeetingDto(
				meetingId: (int)$event['ID'],
				name: $name,
				date: DateTime::createFromTimestamp($meetingData['startTs']),
				chatId: $meetingData['chatId'],
				endDate: DateTime::createFromTimestamp($meetingData['endTs']),
				isAllDay: $meetingData['isAllDay'],
				isRecurring: $isRecurringSource || array_key_exists('RINDEX', $event),
			);
		}

		usort(
			$meetings,
			static fn (GroupMeetingDto $left, GroupMeetingDto $right): int =>
				$right->date->getTimestamp() <=> $left->date->getTimestamp(),
		);

		return $meetings;
	}

	/**
	 * Keeps the previous expanded occurrence with every recurring meeting. The response may later
	 * cut that occurrence off, but call ownership still needs its boundary.
	 *
	 * @param list<GroupMeetingDto> $meetings Newest first.
	 * @return list<GroupMeetingDto>
	 */
	private function attachPreviousRecurrence(array $meetings): array
	{
		$previousByChat = [];
		$enriched = [];
		foreach (array_reverse($meetings) as $meeting)
		{
			$previous = $previousByChat[$meeting->chatId] ?? null;
			$enriched[] = new GroupMeetingDto(
				meetingId: $meeting->meetingId,
				name: $meeting->name,
				date: $meeting->date,
				chatId: $meeting->chatId,
				endDate: $meeting->endDate,
				isAllDay: $meeting->isAllDay,
				isRecurring: true,
				previousOccurrenceEndDate: $previous?->endDate,
			);
			$previousByChat[$meeting->chatId] = $meeting;
		}

		return array_reverse($enriched);
	}

	/**
	 * @param list<GroupMeetingDto> $ordinary Newest first.
	 * @param list<GroupMeetingDto> $recurring Newest first.
	 * @return list<GroupMeetingDto>
	 */
	private function mergeNewestMeetings(array $ordinary, array $recurring, int $limit): array
	{
		$result = [];
		$ordinaryIndex = 0;
		$recurringIndex = 0;
		while (count($result) < $limit)
		{
			$ordinaryMeeting = $ordinary[$ordinaryIndex] ?? null;
			$recurringMeeting = $recurring[$recurringIndex] ?? null;
			if ($ordinaryMeeting === null && $recurringMeeting === null)
			{
				break;
			}

			if (
				$recurringMeeting === null
				|| (
					$ordinaryMeeting !== null
					&& $ordinaryMeeting->date->getTimestamp() >= $recurringMeeting->date->getTimestamp()
				)
			)
			{
				$result[] = $ordinaryMeeting;
				$ordinaryIndex++;
			}
			else
			{
				$result[] = $recurringMeeting;
				$recurringIndex++;
			}
		}

		return $result;
	}

	/**
	 * @return array{startTs: int, endTs: int, isAllDay: bool, chatId: int}|null
	 */
	private function getFinishedMeetingData(array $event, int $now, ?int $fromTs, int $toTs): ?array
	{
		$chatId = $this->getVisibleMeetingChatId($event);
		if ($chatId <= 0)
		{
			return null;
		}

		[$startTs, $endTs, $isAllDay] = $this->eventBoundsUtc($event);
		$isFinished = $startTs > 0 && $endTs > 0 && $endTs < $now;
		if (!$isFinished || ($fromTs !== null && $startTs < $fromTs) || $startTs > $toTs)
		{
			return null;
		}

		return [
			'startTs' => $startTs,
			'endTs' => $endTs,
			'isAllDay' => $isAllDay,
			'chatId' => $chatId,
		];
	}

	private function getVisibleMeetingChatId(array $event): int
	{
		if (($event['IS_ACCESSIBLE_TO_USER'] ?? null) === false)
		{
			return 0;
		}

		$meeting = is_array($event['MEETING'] ?? null) ? $event['MEETING'] : [];

		return (int)($meeting['CHAT_ID'] ?? 0);
	}

	/**
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function eventBoundsUtc(array $event): array
	{
		$startTs = $this->eventTimestampUtc($event, 'DATE_FROM', 'DATE_FROM_TS_UTC', 'TZ_FROM');
		$endTs = $this->eventTimestampUtc($event, 'DATE_TO', 'DATE_TO_TS_UTC', 'TZ_TO');
		$isAllDay = ($event['DT_SKIP_TIME'] ?? null) === 'Y';
		if ($isAllDay && $endTs > 0)
		{
			// Calendar all-day values are date-only and DATE_TO is inclusive. Follow the
			// canonical accessibility read-path: interpret that date in UTC and include it fully.
			$endTs = !empty($event['DATE_TO'])
				? \CCalendar::TimestampUTC($event['DATE_TO']) + \CCalendar::GetDayLen() - 1
				: (new \DateTimeImmutable('@' . $endTs))
					->setTimezone(new \DateTimeZone('UTC'))
					->setTime(23, 59, 59)
					->getTimestamp()
			;
		}

		return [$startTs, $endTs, $isAllDay];
	}

	/**
	 * Recurrence expansion updates DATE_FROM/DATE_TO for each occurrence but deliberately keeps
	 * the stored *_TS_UTC fields of the series. Interpret the expanded wall time in the event's
	 * timezone (important across DST), while all-day dates use Calendar's UTC date convention.
	 * Ordinary events and test/legacy projections retain their scalar UTC fields.
	 */
	private function eventTimestampUtc(
		array $event,
		string $dateField,
		string $timestampField,
		string $timezoneField,
	): int
	{
		if (array_key_exists('RINDEX', $event) && !empty($event[$dateField]))
		{
			if (($event['DT_SKIP_TIME'] ?? null) === 'Y')
			{
				return \CCalendar::TimestampUTC($event[$dateField]);
			}
			$timezone = trim((string)($event[$timezoneField] ?? ''));

			return (int)\CCalendar::createDateTimeObjectFromString(
				$event[$dateField],
				$timezone !== '' ? $timezone : 'UTC',
			)->format('U');
		}

		return (int)($event[$timestampField] ?? 0);
	}

	/**
	 * A user allowed to see the title but not the whole event gets the name with the
	 * accessibility label appended (ApplyAccessRestrictions). The label belongs to the calendar
	 * interface, so it is cut off here. The name is what the consumer quotes. The label is taken
	 * from the same message the calendar appends, so it matches on any language.
	 *
	 * Only rows the calendar actually trimmed are touched: it unsets the fields it hides, and
	 * ATTENDEE_LIST is otherwise always present in a GetList row. Without that condition a
	 * meeting genuinely named "... [Busy]" would lose its ending.
	 */
	private function stripAccessibilityLabel(array $event, string $name): string
	{
		if ($name === '' || array_key_exists('ATTENDEE_LIST', $event))
		{
			return $name;
		}

		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . self::CALENDAR_LANG_FILE);
		$accessibility = (string)($event['ACCESSIBILITY'] ?? '');
		$label = $accessibility === ''
			? null
			: Loc::getMessage('EC_ACCESSIBILITY_' . mb_strtoupper($accessibility))
		;

		if ($label === null || $label === '')
		{
			return $name;
		}

		$suffix = ' [' . $label . ']';

		return str_ends_with($name, $suffix) ? mb_substr($name, 0, -mb_strlen($suffix)) : $name;
	}

	/**
	 * @return array<int, int|null>  [eventId => ?chatId]
	 */
	private function formatEvents(array $rawEvents): array
	{
		$map = [];

		foreach ($rawEvents as $event)
		{
			$meeting = is_array($event['MEETING'] ?? null) ? $event['MEETING'] : [];
			$map[(int)$event['ID']] = (int)$meeting['CHAT_ID'] ?? null;
		}

		return $map;
	}
}
