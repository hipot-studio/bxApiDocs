<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage calendar
 * @copyright 2001-2023 Bitrix
 */

namespace Bitrix\Calendar\Rooms;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class OccupancyChecker
{
	private const OPTION_NAME = 'isOccupancyCheckerEnabled';
	private const OPTION_ENABLED = 'Y';
	private const OPTION_DISABLED = 'N';

	private const CHECK_LIMIT = 2 * 365 * 86400;
	private const ZERO_END_EVENTS_CHUNK_SIZE = 300;
	private const SECTION_IDS_CHUNK_SIZE = 300;
	private const EVENT_IDS_CHUNK_SIZE = 300;
	private const BATCH_QUERY_GROUP_SPAN = 31 * 86400;
	private const DISTURBING_EVENTS_LIMIT = 3;
	private const KEY_PART_START = 'start';
	private const KEY_PART_END = 'end';
	private const BATCH_TO_TIMESTAMP_FIELD = '~OCCUPANCY_BATCH_TO_TS_UTC';
	private const MATCHED_SECTION_IDS_FIELD = '~OCCUPANCY_MATCHED_SECTION_IDS';
	private const EVENT_ORIGIN_CANDIDATE = 'candidate';
	private const EVENT_ORIGIN_PERSISTED = 'persisted';
	private const EVENT_ORIGIN_EXPANDED = 'expanded';
	private const INVALID_INTERVAL_FIELD = '~OCCUPANCY_INVALID_INTERVAL';

	private array $timeline = [];
	private int $checkEventId = 0;
	private array $cachedEvents = [];

	public function __construct()
	{

	}

	/**
	 * Checks if saving event location booking has intersection with other events location booking.
	 * If so, returns \Bitrix\Main\Result object with disturbing event data.
	 * @param array $event
	 * @param array $excludedEventIds
	 * @return Result
	 */
	public function check(array $event, array $excludedEventIds = []): Result
	{
		$result = new Result();
		$this->timeline = [];
		$this->cachedEvents = [];
		$this->checkEventId = 0;
		$excludedEventIds = $this->normalizeExcludedEventIds($excludedEventIds);

		$event = $this->prepareEventForOccupancy($event);

		if ($event === null || !$this->canCheck($event))
		{
			return $result;
		}

		$location = Util::parseLocation($event['LOCATION']['NEW']);
		$roomId = $location['room_id'];

		if (!$roomId)
		{
			return $result;
		}

		$eventsForCheck = $this->getEventsForCheck($event);

		$eventForExistingEvents = $event;
		$eventForExistingEvents['DATE_FROM'] = $this->getExistingEventsFromLimit($event, $eventsForCheck);
		$eventForExistingEvents['DATE_FROM_TS_UTC'] = $this->getEventStartTimestampUtc($eventForExistingEvents);
		$existingEvents = $this->getExistingEvents($roomId, $eventForExistingEvents);
		try
		{
			$this->fillTimeline($event, $eventsForCheck, $existingEvents, $excludedEventIds);
		}
		catch (ObjectException)
		{
			$this->addInvalidIntervalError($result);

			return $result;
		}
		asort($this->timeline, SORT_NUMERIC);

		$checkingEventsStartedCount = 0;
		$existingEventsStartedCount = 0;
		$activeExistingEvents = [];
		$disturbingEventDates = [];
		$isDisturbingEventsAmountOverShowLimit = false;
		$disturbingEventDatesUtc = [];
		$disturbingEventSectionIds = [];
		foreach ($this->timeline as $key => $value)
		{
			[$eventId, $repeatNumber, $isEnd] = $this->parseTimelineKey($key);
			$cachedEventKey = $this->getCachedEventKey($eventId, $repeatNumber);
			if ($eventId === $this->checkEventId)
			{
				if ($isEnd)
				{
					$checkingEventsStartedCount--;
				}
				else
				{
					$checkingEventsStartedCount++;
				}
			}
			else if ($isEnd)
			{
				$existingEventsStartedCount--;
				unset($activeExistingEvents[$cachedEventKey]);
			}
			else
			{
				$existingEventsStartedCount++;
				$activeExistingEvents[$cachedEventKey] = $this->cachedEvents[$cachedEventKey] ?? [];
			}

			if ($checkingEventsStartedCount > 0 && $existingEventsStartedCount > 0)
			{
				$disturbingEvent = $this->cachedEvents[$cachedEventKey] ?? [];
				if ($eventId === $this->checkEventId)
				{
					foreach ($activeExistingEvents as $activeExistingEvent)
					{
						$disturbingEvent = $activeExistingEvent;
						break;
					}
				}
				if (count($disturbingEventDates) >= self::DISTURBING_EVENTS_LIMIT)
				{
					$isDisturbingEventsAmountOverShowLimit = true;
					break;
				}
				$sectionIds = $disturbingEvent[self::MATCHED_SECTION_IDS_FIELD] ?? [
					(int)($disturbingEvent['SECTION_ID'] ?? 0),
				];
				foreach ($sectionIds as $sectionId)
				{
					$sectionId = (int)$sectionId;
					if ($sectionId > 0)
					{
						$disturbingEventSectionIds[$sectionId] = $sectionId;
					}
				}
				$disturbingEventDate = $this->getEventFormattedDate($disturbingEvent);
				$disturbingEventDateUtc = $this->getEventDateUtc($disturbingEvent);
				if (!in_array($disturbingEventDate, $disturbingEventDates, true))
				{
					$disturbingEventDates[] = $disturbingEventDate;
					if ($disturbingEventDateUtc !== null)
					{
						$disturbingEventDatesUtc[] = $disturbingEventDateUtc;
					}
				}
			}
		}

		if (!empty($disturbingEventDates))
		{
			$result->addError(new Error('ROOM IS OCCUPIED'));
			$result->setData([
				'disturbingEventsFormatted' => implode(', ', $disturbingEventDates),
				'isDisturbingEventsAmountOverShowLimit' => $isDisturbingEventsAmountOverShowLimit,
				'disturbingEventsUtc' => $disturbingEventDatesUtc,
				'disturbingEventSectionIds' => array_values($disturbingEventSectionIds),
			]);
		}

		return $result;
	}

	/**
	 * Checks several events while loading existing bookings once per room.
	 *
	 * @param array $events
	 * @param array $excludedEventIds
	 * @return Result
	 */
	public function checkBatch(array $events, array $excludedEventIds = []): Result
	{
		$result = new Result();
		$this->timeline = [];
		$this->cachedEvents = [];
		$this->checkEventId = 0;
		$excludedEventIds = $this->normalizeExcludedEventIds($excludedEventIds);

		$eventsByRoom = [];
		foreach ($events as $event)
		{
			if (!is_array($event))
			{
				continue;
			}

			$preparedEvent = $this->prepareEventForOccupancy($event);
			if ($preparedEvent === null || !$this->canCheck($preparedEvent))
			{
				continue;
			}

			$roomId = (int)(Util::parseLocation($preparedEvent['LOCATION']['NEW'])['room_id'] ?? 0);
			if ($roomId <= 0)
			{
				continue;
			}

			$eventsByRoom[$roomId][] = $preparedEvent;
		}

		$candidateConflict = $this->findCandidateConflict($eventsByRoom);
		if ($candidateConflict !== null)
		{
			$this->addBatchConflict($result, $candidateConflict);

			return $result;
		}

		$limitEventsByRoom = [];
		foreach ($eventsByRoom as $roomId => $roomEvents)
		{
			$limitEventsByRoom[$roomId] = $this->getBatchLimitEvent($roomEvents);
		}
		$existingEventsByRoom = $this->getExistingEventsForRooms($limitEventsByRoom);

		foreach ($eventsByRoom as $roomId => $roomEvents)
		{
			$limitEvent = $limitEventsByRoom[$roomId];
			$existingEvents = $existingEventsByRoom[$roomId] ?? [];
			$occupancyData = $this->findBatchConflict(
				$roomEvents,
				$existingEvents,
				$limitEvent,
				$excludedEventIds,
			);

			if ($occupancyData === null)
			{
				continue;
			}

			$this->addBatchConflict($result, $occupancyData);

			return $result;
		}

		return $result;
	}

	/**
	 * @param array $event
	 * @return bool
	 */
	private function canCheck(array $event): bool
	{
		return
			$this->isEnabled()
			&& !empty($event['LOCATION']['NEW'])
			&& !empty($event['DATE_FROM_TS_UTC'])
			&& !empty($event['DATE_FROM'])
			&& !empty($event['DATE_TO'])
		;
	}

	/**
	 * @param array $excludedEventIds
	 * @return array<int, true>
	 */
	private function normalizeExcludedEventIds(array $excludedEventIds): array
	{
		$excludedEventIdsMap = [];
		foreach ($excludedEventIds as $eventId)
		{
			$eventId = (int)$eventId;
			if ($eventId > 0)
			{
				$excludedEventIdsMap[$eventId] = true;
			}
		}

		return $excludedEventIdsMap;
	}

	private function addInvalidIntervalError(Result $result): void
	{
		$result->addError(new Error(
			'ROOM OCCUPANCY INTERVAL IS INVALID',
			OccupancyCheckerException::INVALID_INTERVAL_ERROR_CODE,
		));
		$result->setData($this->getInvalidIntervalData());
	}

	private function getInvalidIntervalData(): array
	{
		return [
			'disturbingEventsFormatted' => '',
			'isDisturbingEventsAmountOverShowLimit' => false,
			'disturbingEventsUtc' => [],
			'disturbingEventSectionIds' => [],
		];
	}

	private function getInvalidBatchIntervalData(): array
	{
		return [self::INVALID_INTERVAL_FIELD => true] + $this->getInvalidIntervalData();
	}

	private function addBatchConflict(Result $result, array $occupancyData): void
	{
		if (!empty($occupancyData[self::INVALID_INTERVAL_FIELD]))
		{
			$this->addInvalidIntervalError($result);

			return;
		}

		$result->addError(new Error('ROOM IS OCCUPIED'));
		$result->setData($occupancyData);
	}

	/**
	 * @param array $event
	 * @return array|null
	 */
	private function prepareEventForOccupancy(array $event): ?array
	{
		$ruleData = RecurrenceRuleSource::resolveForOccupancy($event);
		if ($ruleData === false)
		{
			$event['RRULE'] = [];

			return $event;
		}

		if (empty($ruleData))
		{
			return $event;
		}

		$rule = \CCalendarEvent::ParseRRULE($ruleData);
		if (
			empty($rule['FREQ'])
			|| !in_array($rule['FREQ'], ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)
		)
		{
			return null;
		}

		$rule['INTERVAL'] = max(1, (int)($rule['INTERVAL'] ?? 1));
		if ($rule['FREQ'] === 'WEEKLY' && isset($rule['COUNT']))
		{
			$hasByDay = is_string($ruleData)
				? preg_match('/(?:^|;)BYDAY=[^;]+(?:;|$)/', $ruleData) === 1
				: !empty($rule['BYDAY'])
			;
			$byDay = $hasByDay
				? \CCalendar::normalizeRRuleByDay($rule['BYDAY'] ?? null)
				: []
			;
			if (empty($byDay))
			{
				$weekDay = $this->getEventWeekDay($event);
				if ($weekDay === null)
				{
					return null;
				}

				$byDay = [$weekDay => $weekDay];
			}

			$rule['BYDAY'] = $byDay;
		}

		$event['RRULE'] = $rule;
		if (isset($rule['COUNT']))
		{
			if ((int)($event['DATE_TO_TS_UTC'] ?? 0) <= 0)
			{
				$event['DATE_TO_TS_UTC'] = \CCalendarEvent::calculateUntilForCountRRule($event);
			}
			if ((int)$event['DATE_TO_TS_UTC'] <= 0)
			{
				return null;
			}
		}
		elseif ((int)($event['DATE_TO_TS_UTC'] ?? 0) <= 0)
		{
			$untilTs = (int)($rule['UNTIL_TS'] ?? 0);
			$event['DATE_TO_TS_UTC'] = $untilTs > 0
				? $untilTs + \CCalendar::GetDayLen()
				: 0
			;
			if ($event['DATE_TO_TS_UTC'] <= 0)
			{
				$event['DATE_TO_TS_UTC'] = $this->getDateTimestampUtc($event, 'DATE_TO', 'TZ_TO');
			}
		}

		return $event;
	}

	/**
	 * @return bool
	 */
	private function isEnabled(): bool
	{
		return Option::get('calendar', self::OPTION_NAME, self::OPTION_ENABLED, '-') !== self::OPTION_DISABLED;
	}

	/**
	 * @param array $event
	 * @param array|null $limitEvent
	 * @return array
	 */
	protected function getEventsForCheck(array $event, ?array $limitEvent = null): array
	{
		if (empty($event['RRULE']))
		{
			return [$event];
		}

		$checkedEvents = [];
		$toLimit = $this->getCheckLimit($limitEvent ?? $event);
		$parseParams = [
			'userId' => \CCalendar::GetCurUserId(),
			'fromLimit' => null,
			'toLimitTs' => $toLimit,
			'loadLimit' =>  null,
			'instanceCount' => false,
			'preciseLimits' => false,
			'checkPermission' => false,
			'alignWeeklyRecurrenceStart' => $this->shouldAlignWeeklyRecurrenceStart($event),
		];

		if ($limitEvent !== null)
		{
			$eventLength = (int)($event['DT_LENGTH'] ?? 0);
			if ($eventLength <= 0)
			{
				$eventLength = (int)($event['DATE_TO_TS_UTC'] ?? 0)
					- (int)($event['DATE_FROM_TS_UTC'] ?? 0)
				;
			}
			if ($eventLength <= 0)
			{
				$eventLength = \CCalendar::GetDayLen();
			}

			$recurrencePeriod = \CCalendarEvent::getFreqDuration($event['RRULE']['FREQ'] ?? '')
				* max(1, (int)($event['RRULE']['INTERVAL'] ?? 1))
			;
			$recurrenceAlignment = ($event['RRULE']['FREQ'] ?? '') === 'YEARLY'
				? min($recurrencePeriod, \CCalendar::GetDayLen() * 31)
				: $recurrencePeriod
			;
			$fromLimit = $this->getEventStartTimestampUtc($limitEvent) - $eventLength - $recurrenceAlignment;
			$fromLimit -= (int)($event['TZ_OFFSET_FROM'] ?? 0);
			$parseParams['fromLimitTs'] = $fromLimit;
		}

		\CCalendarEvent::ParseRecursion(
			$checkedEvents,
			$event,
			$parseParams,
		);

		return $checkedEvents;
	}

	/**
	 * @param array $event
	 * @param array $eventsForCheck
	 * @return string
	 */
	private function getExistingEventsFromLimit(array $event, array $eventsForCheck): string
	{
		$fromLimit = $event['DATE_FROM'];
		$fromLimitTimestamp = $this->getEventStartTimestampUtc($event);

		foreach ($eventsForCheck as $eventForCheck)
		{
			if (empty($eventForCheck['DATE_FROM']))
			{
				continue;
			}

			$eventFromTimestamp = $this->getEventStartTimestampUtc($eventForCheck);
			if ($eventFromTimestamp < $fromLimitTimestamp)
			{
				$fromLimit = $eventForCheck['DATE_FROM'];
				$fromLimitTimestamp = $eventFromTimestamp;
			}
		}

		return $fromLimit;
	}

	/**
	 * @param array $event
	 * @return bool
	 */
	private function shouldAlignWeeklyRecurrenceStart(array $event): bool
	{
		return
			!empty($event['ORIGINAL_RECURSION_ID'])
			|| !empty($event['RELATIONS']['ORIGINAL_RECURSION_ID'])
		;
	}

	/**
	 * @param array $event
	 * @return int
	 */
	private function getCheckLimit(array $event): int
	{
		$fromTimestamp = $this->getEventStartTimestampUtc($event);
		if (isset($event[self::BATCH_TO_TIMESTAMP_FIELD]))
		{
			return max($fromTimestamp, (int)$event[self::BATCH_TO_TIMESTAMP_FIELD]);
		}

		$toTimestamp = empty($event['RRULE'])
			? $this->getEventEndTimestampUtc($event)
			: (int)($event['DATE_TO_TS_UTC'] ?? 0)
		;
		if ($toTimestamp <= 0)
		{
			$toTimestamp = (int)($event['DATE_TO_TS_UTC'] ?? 0);
		}

		return min($fromTimestamp + self::CHECK_LIMIT, max($fromTimestamp, $toTimestamp));
	}

	private function getBatchLimitEvent(array $events): array
	{
		if (count($events) === 1)
		{
			return $events[0];
		}

		$limitEvent = $events[0];
		$fromTimestamp = PHP_INT_MAX;
		$toTimestamp = 0;
		foreach ($events as $event)
		{
			$fromTimestamp = min($fromTimestamp, $this->getEventStartTimestampUtc($event));
			$toTimestamp = max($toTimestamp, $this->getCheckLimit($event));
		}

		$limitEvent['DATE_FROM_TS_UTC'] = $fromTimestamp;
		$limitEvent['DATE_TO_TS_UTC'] = $toTimestamp;
		$limitEvent[self::BATCH_TO_TIMESTAMP_FIELD] = $toTimestamp;
		$limitEvent['DT_SKIP_TIME'] = 'N';
		$limitEvent['RRULE'] = [];
		unset(
			$limitEvent['DATE_FROM'],
			$limitEvent['DATE_TO'],
			$limitEvent['TZ_FROM'],
			$limitEvent['TZ_TO'],
		);

		return $limitEvent;
	}

	private function getEventStartTimestampUtc(array $event): int
	{
		if (($event['DT_SKIP_TIME'] ?? null) === 'Y')
		{
			$fromTimestamp = $this->getAllDayTimestamp($event, 'DATE_FROM');
			if ($fromTimestamp > 0)
			{
				return $fromTimestamp;
			}
		}

		$fromTimestamp = $this->getDateTimestampUtc($event, 'DATE_FROM', 'TZ_FROM');
		if ($fromTimestamp <= 0)
		{
			$fromTimestamp = (int)($event['DATE_FROM_TS_UTC'] ?? 0);
		}

		return $fromTimestamp;
	}

	private function getEventEndTimestampUtc(array $event): int
	{
		if (($event['DT_SKIP_TIME'] ?? null) === 'Y')
		{
			$toTimestamp = $this->getAllDayTimestamp($event, 'DATE_TO');
			if ($toTimestamp > 0)
			{
				return $toTimestamp + \CCalendar::GetDayLen();
			}
		}

		return $this->getDateTimestampUtc($event, 'DATE_TO', 'TZ_TO');
	}

	private function getAllDayTimestamp(array $event, string $dateField): int
	{
		if (empty($event[$dateField]))
		{
			return 0;
		}

		return (int)\CCalendar::Timestamp($event[$dateField], false, true);
	}

	private function getEventWeekDay(array $event): ?string
	{
		if (empty($event['DATE_FROM']))
		{
			return null;
		}

		try
		{
			$timezone = !empty($event['TZ_FROM'])
				? new \DateTimeZone($event['TZ_FROM'])
				: null
			;
			$date = new DateTime($event['DATE_FROM'], null, $timezone);
			$weekDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

			return $weekDays[(int)$date->format('w')] ?? null;
		}
		catch (ObjectException $exception)
		{
			return null;
		}
	}

	private function getDateTimestampUtc(
		array $event,
		string $dateField,
		string $timezoneField,
		bool $useExplicitOffset = true,
	): int
	{
		if (empty($event[$dateField]))
		{
			return 0;
		}

		$timestamp = (int)\CCalendar::Timestamp($event[$dateField], false, true);
		if ($timestamp <= 0)
		{
			return 0;
		}
		if (!\CTimeZone::OptionEnabled())
		{
			return $timestamp;
		}

		$offsetField = $timezoneField === 'TZ_FROM' ? 'TZ_OFFSET_FROM' : 'TZ_OFFSET_TO';
		if ($useExplicitOffset && array_key_exists($offsetField, $event))
		{
			$offset = (int)$event[$offsetField];
		}
		else
		{
			$timezoneName = (string)($event[$timezoneField] ?? '');
			if ($timezoneName === '')
			{
				return 0;
			}

			try
			{
				return (new \DateTimeImmutable(
					gmdate('Y-m-d H:i:s', $timestamp),
					new \DateTimeZone($timezoneName),
				))->getTimestamp();
			}
			catch (\Throwable)
			{
				return 0;
			}
		}

		return $timestamp - $offset;
	}

	/**
	 * @param array $event
	 * @return array
	 * @throws ObjectException
	 */
	private function getEventTimestamps(array $event, string $origin): array
	{
		if (
			($event['DT_SKIP_TIME'] ?? null) === 'Y'
			&& $origin === self::EVENT_ORIGIN_PERSISTED
		)
		{
			$timestampFrom = (int)($event['DATE_FROM_TS_UTC'] ?? 0);
			$eventLength = (int)($event['DT_LENGTH'] ?? 0);
			$timestampTo = $timestampFrom > 0 && $eventLength > 0
				? $timestampFrom + $eventLength
				: (int)($event['DATE_TO_TS_UTC'] ?? 0)
			;
		}
		elseif (($event['DT_SKIP_TIME'] ?? null) === 'Y')
		{
			$timestampFrom = $this->getAllDayTimestamp($event, 'DATE_FROM');
			$timestampTo = $this->getAllDayTimestamp($event, 'DATE_TO') + \CCalendar::GetDayLen();
		}
		elseif (
			$origin === self::EVENT_ORIGIN_PERSISTED
			|| (empty($event['DATE_FROM']) && empty($event['DATE_TO']))
		)
		{
			$timestampFrom = (int)($event['DATE_FROM_TS_UTC'] ?? 0);
			$timestampTo = (int)($event['DATE_TO_TS_UTC'] ?? 0);
			if ($timestampTo <= 0 && $timestampFrom > 0)
			{
				$timestampTo = $timestampFrom + (int)($event['DT_LENGTH'] ?? 0);
			}
		}
		else
		{
			$useExplicitOffset = $origin !== self::EVENT_ORIGIN_EXPANDED;
			$timestampFrom = $this->getDateTimestampUtc(
				$event,
				'DATE_FROM',
				'TZ_FROM',
				$useExplicitOffset,
			);
			$timestampTo = $this->getDateTimestampUtc(
				$event,
				'DATE_TO',
				'TZ_TO',
				$useExplicitOffset,
			);
		}

		if ($timestampFrom > 0 && $timestampFrom === $timestampTo)
		{
			$timestampTo += \CCalendar::GetDayLen();
		}

		if ($timestampFrom <= 0 || $timestampTo <= $timestampFrom)
		{
			throw new ObjectException('Event timestamps are invalid.');
		}

		//This is done to check weak inequality
		$timestampFrom++;
		$timestampTo--;

		return [$timestampFrom, $timestampTo];
	}

	private function getInstanceOrigin(array $sourceEvent, string $sourceOrigin): string
	{
		return empty($sourceEvent['RRULE']) ? $sourceOrigin : self::EVENT_ORIGIN_EXPANDED;
	}

	/**
	 * @param int $roomId
	 * @param array $event
	 * @return array|null
	 */
	protected function getExistingEvents(int $roomId, array $event): ?array
	{
		$sectionIds = $this->getRoomSectionIds($roomId);
		$existingEvents = $this->getExistingEventsBySections($sectionIds, $event);
		if ($existingEvents === null)
		{
			return null;
		}

		$matchedSectionIdsByEventId = $this->getMatchedSectionIdsByEventId($existingEvents, $sectionIds);
		foreach ($existingEvents as &$existingEvent)
		{
			$eventId = (int)($existingEvent['ID'] ?? 0);
			if ($eventId > 0 && isset($matchedSectionIdsByEventId[$eventId]))
			{
				$existingEvent[self::MATCHED_SECTION_IDS_FIELD] = $matchedSectionIdsByEventId[$eventId];
			}
		}
		unset($existingEvent);

		return $existingEvents;
	}

	/**
	 * @param array<int, array> $limitEventsByRoom
	 * @return array<int, array>
	 */
	protected function getExistingEventsForRooms(array $limitEventsByRoom): array
	{
		if ($limitEventsByRoom === [])
		{
			return [];
		}

		$additionalSectionIdsByRoom = AccessibilityManager::getFullAdditionalLocationAccessibilityConnection();
		$sectionIdsByRoom = [];
		foreach (array_keys($limitEventsByRoom) as $roomId)
		{
			$roomId = (int)$roomId;
			$sectionIdsByRoom[$roomId] = $this->getRoomSectionIds(
				$roomId,
				$additionalSectionIdsByRoom[$roomId] ?? [],
			);
		}

		$existingEventsByRoom = array_fill_keys(array_keys($limitEventsByRoom), []);
		$existingEventIdsByRoom = array_fill_keys(array_keys($limitEventsByRoom), []);
		foreach ($this->groupLimitEventsByTime($limitEventsByRoom) as $limitEventsGroup)
		{
			$roomIdsBySectionId = [];
			foreach (array_keys($limitEventsGroup) as $roomId)
			{
				foreach ($sectionIdsByRoom[$roomId] ?? [] as $sectionId)
				{
					$roomIdsBySectionId[$sectionId][$roomId] = true;
				}
			}
			ksort($roomIdsBySectionId, SORT_NUMERIC);

			$globalLimitEvent = $this->getBatchLimitEvent(array_values($limitEventsGroup));
			foreach (array_chunk(array_keys($roomIdsBySectionId), self::SECTION_IDS_CHUNK_SIZE) as $sectionIds)
			{
				$existingEvents = $this->getExistingEventsBySections($sectionIds, $globalLimitEvent) ?? [];
				$matchedSectionIdsByEventId = $this->getMatchedSectionIdsByEventId($existingEvents, $sectionIds);
				foreach ($existingEvents as $existingEvent)
				{
					$eventId = (int)($existingEvent['ID'] ?? 0);
					foreach ($matchedSectionIdsByEventId[$eventId] ?? [] as $sectionId)
					{
						foreach (array_keys($roomIdsBySectionId[$sectionId] ?? []) as $roomId)
						{
							if ($eventId > 0 && isset($existingEventIdsByRoom[$roomId][$eventId]))
							{
								$eventIndex = $existingEventIdsByRoom[$roomId][$eventId];
								$matchedSectionIds = $existingEventsByRoom[$roomId][$eventIndex][self::MATCHED_SECTION_IDS_FIELD] ?? [];
								$matchedSectionIds[$sectionId] = $sectionId;
								$existingEventsByRoom[$roomId][$eventIndex][self::MATCHED_SECTION_IDS_FIELD] = $matchedSectionIds;
								continue;
							}

							$eventForRoom = $existingEvent;
							$eventForRoom[self::MATCHED_SECTION_IDS_FIELD] = [$sectionId => $sectionId];
							$existingEventsByRoom[$roomId][] = $eventForRoom;
							if ($eventId > 0)
							{
								$existingEventIdsByRoom[$roomId][$eventId] = array_key_last($existingEventsByRoom[$roomId]);
							}
						}
					}
				}
			}
		}

		return $existingEventsByRoom;
	}

	/**
	 * @param array<int, array> $limitEventsByRoom
	 * @return array<int, array<int, array>>
	 */
	private function groupLimitEventsByTime(array $limitEventsByRoom): array
	{
		$intervals = [];
		foreach ($limitEventsByRoom as $roomId => $limitEvent)
		{
			$from = $this->getEventStartTimestampUtc($limitEvent);
			$to = max($from, $this->getCheckLimit($limitEvent));
			$intervals[] = [
				'roomId' => (int)$roomId,
				'event' => $limitEvent,
				'from' => $from,
				'to' => $to,
			];
		}
		usort(
			$intervals,
			static fn(array $first, array $second): int => [$first['from'], $first['roomId']]
				<=> [$second['from'], $second['roomId']],
		);

		$groups = [];
		$currentGroup = [];
		$groupFrom = 0;
		$groupTo = 0;
		foreach ($intervals as $interval)
		{
			$newGroupFrom = $currentGroup === [] ? $interval['from'] : min($groupFrom, $interval['from']);
			$newGroupTo = $currentGroup === [] ? $interval['to'] : max($groupTo, $interval['to']);
			if ($currentGroup !== [] && $newGroupTo - $newGroupFrom > self::BATCH_QUERY_GROUP_SPAN)
			{
				$groups[] = $currentGroup;
				$currentGroup = [];
				$newGroupFrom = $interval['from'];
				$newGroupTo = $interval['to'];
			}

			$currentGroup[$interval['roomId']] = $interval['event'];
			$groupFrom = $newGroupFrom;
			$groupTo = $newGroupTo;
		}
		if ($currentGroup !== [])
		{
			$groups[] = $currentGroup;
		}

		return $groups;
	}

	/**
	 * @param array<int, array> $events
	 * @param int[] $sectionIds
	 * @return array<int, int[]>
	 */
	protected function getMatchedSectionIdsByEventId(array $events, array $sectionIds): array
	{
		$sectionIdsMap = array_fill_keys(array_map('intval', $sectionIds), true);
		$eventIds = [];
		$matchedSectionIdsByEventId = [];
		foreach ($events as $event)
		{
			$eventId = (int)($event['ID'] ?? 0);
			$sectionId = (int)($event['SECTION_ID'] ?? 0);
			if ($eventId <= 0)
			{
				continue;
			}

			$eventIds[$eventId] = $eventId;
			if (isset($sectionIdsMap[$sectionId]))
			{
				$matchedSectionIdsByEventId[$eventId][$sectionId] = $sectionId;
			}
		}

		if (\Bitrix\Calendar\Util::isSectionStructureConverted() || $eventIds === [])
		{
			return array_map('array_values', $matchedSectionIdsByEventId);
		}
		$matchedSectionIdsByEventId = [];

		foreach (array_chunk(array_values($eventIds), self::EVENT_IDS_CHUNK_SIZE) as $eventIdsChunk)
		{
			$eventSectionRows = $this->getEventSectionRows($eventIdsChunk, array_keys($sectionIdsMap));
			foreach ($eventSectionRows as $eventSectionRow)
			{
				$eventId = (int)($eventSectionRow['EVENT_ID'] ?? 0);
				$sectionId = (int)($eventSectionRow['SECT_ID'] ?? 0);
				if ($eventId > 0 && isset($sectionIdsMap[$sectionId]))
				{
					$matchedSectionIdsByEventId[$eventId][$sectionId] = $sectionId;
				}
			}
		}

		return array_map('array_values', $matchedSectionIdsByEventId);
	}

	/**
	 * @param int[] $eventIds
	 * @param int[] $sectionIds
	 * @return array<int, array{EVENT_ID: int|string, SECT_ID: int|string}>
	 */
	protected function getEventSectionRows(array $eventIds, array $sectionIds): array
	{
		return \Bitrix\Calendar\Internals\EventSectTable::query()
			->setSelect(['EVENT_ID', 'SECT_ID'])
			->whereIn('EVENT_ID', $eventIds)
			->whereIn('SECT_ID', $sectionIds)
			->exec()
			->fetchAll()
		;
	}

	private function getRoomSectionIds(int $roomId, ?array $additionalSectionIds = null): array
	{
		$sections = [$roomId];
		$additionalLocationConnection = $additionalSectionIds
			?? AccessibilityManager::getAdditionalLocationAccessibilityConnection($roomId)
		;
		if ($additionalLocationConnection !== [])
		{
			$sections = [...$sections, ...$additionalLocationConnection];
		}

		$sections = array_values(array_unique(array_filter(array_map('intval', $sections))));
		sort($sections, SORT_NUMERIC);

		return $sections;
	}

	protected function getExistingEventsBySections(array $sections, array $event): ?array
	{
		$arSelect = [
			'ID',
			'SECTION_ID',
			'RRULE',
			'EXDATE',
			'DT_SKIP_TIME',
			'DT_LENGTH',
			'PARENT_ID',
			'DATE_FROM',
			'DATE_TO',
			'PARENT_ID',
			'CAL_TYPE',
			'TZ_FROM',
			'TZ_TO',
			'TZ_OFFSET_FROM',
			'TZ_OFFSET_TO',
			'DATE_FROM_TS_UTC',
			'DATE_TO_TS_UTC',
			'CREATED_BY',
			'ACCESSIBILITY',
			'REMIND',
			'MEETING_HOST',
			'MEETING_STATUS',
			'IMPORTANCE',
			'PRIVATE_EVENT',
		];

		$toLimit = $this->getCheckLimit($event);
		$fromLimit = max(
			1,
			(int)$event['DATE_FROM_TS_UTC'] - \CCalendar::GetDayLen(),
		);

		$arFilter = [
			'SECTION' => $sections,
			'CAL_TYPE' => \Bitrix\Calendar\Rooms\Manager::TYPE,
			'FROM_LIMIT' => DateTime::createFromTimestamp($fromLimit)
				->disableUserTime()
				->toString(),
			'TO_LIMIT' => DateTime::createFromTimestamp($toLimit)
				->disableUserTime()
				->toString(),
			'DELETED' => 'N',
			'ACTIVE' => 'Y',
		];
		$baseParams = [
			'arSelect' => $arSelect,
			'arFilter' => $arFilter,
			'parseRecursion' => false,
			'loadOriginalRecursion' => true,
			'preserveRRuleSource' => true,
			'fetchAttendees' => false,
			'fetchMeetings' => false,
			'setDefaultLimit' => false,
			'checkPermissions' => false,
			'parseDescription' => false,
			'fetchSection' => false,
			'getUserfields' => false,
		];

		$existingEvents = \CCalendarEvent::GetList([
			...$baseParams,
			'includeDateToTsUtcZero' => false,
			'limit' => null,
		]);

		$lastId = 0;
		while (true)
		{
			$zeroEvents = \CCalendarEvent::GetList([
				...$baseParams,
				'arFilter' => [
					...$arFilter,
					'>ID' => $lastId,
				],
				'arOrder' => ['ID' => 'ASC'],
				'onlyDateToTsUtcZero' => true,
				'limit' => self::ZERO_END_EVENTS_CHUNK_SIZE,
			]);
			if ($zeroEvents === [])
			{
				break;
			}

			$nextLastId = $lastId;
			foreach ($zeroEvents as $zeroEvent)
			{
				$nextLastId = max($nextLastId, (int)($zeroEvent['ID'] ?? 0));
				if ($this->hasPotentialOccupancyInstance($zeroEvent, $event))
				{
					$existingEvents[] = $zeroEvent;
				}
			}

			if ($nextLastId <= $lastId || count($zeroEvents) < self::ZERO_END_EVENTS_CHUNK_SIZE)
			{
				break;
			}
			$lastId = $nextLastId;
		}

		return $existingEvents;
	}

	protected function hasPotentialOccupancyInstance(array $event, array $limitEvent): bool
	{
		$eventForOccupancy = $this->prepareEventForOccupancy($event);
		if ($eventForOccupancy === null)
		{
			return false;
		}

		$eventOrigin = $this->getInstanceOrigin($eventForOccupancy, self::EVENT_ORIGIN_PERSISTED);
		foreach ($this->getEventsForCheck($eventForOccupancy, $limitEvent) as $eventInstance)
		{
			if ($this->hasTemporalIntersection($limitEvent, $eventInstance, $eventOrigin))
			{
				return true;
			}
		}

		return false;
	}

	private function hasTemporalIntersection(array $event, array $existingEvent, string $existingEventOrigin): bool
	{
		try
		{
			[$eventFrom, $eventTo] = $this->getEventTimestamps($event, self::EVENT_ORIGIN_CANDIDATE);
			[$existingEventFrom, $existingEventTo] = $this->getEventTimestamps($existingEvent, $existingEventOrigin);
		}
		catch (ObjectException)
		{
			return false;
		}

		return $eventFrom <= $existingEventTo && $existingEventFrom <= $eventTo;
	}

	/**
	 * @param array $event
	 * @param array $eventsForCheck
	 * @param array $existingEvents
	 * @param array<int, true> $excludedEventIds
	 * @return void
	 */
	private function fillTimeline(
		array $event,
		array $eventsForCheck,
		array $existingEvents,
		array $excludedEventIds = [],
	): void
	{
		if (!empty($event['ID']) && $event['ID'] > 0)
		{
			$this->checkEventId = $event['ID'];
		}

		$currentIndexByEventId = [];
		foreach ($existingEvents as $existingEvent)
		{
			if (isset($excludedEventIds[(int)($existingEvent['ID'] ?? 0)]))
			{
				continue;
			}

			$existingEventForOccupancy = $this->prepareEventForOccupancy($existingEvent);
			if ($existingEventForOccupancy === null)
			{
				continue;
			}

			$existingEventOrigin = $this->getInstanceOrigin(
				$existingEventForOccupancy,
				self::EVENT_ORIGIN_PERSISTED,
			);
			foreach ($this->getEventsForCheck($existingEventForOccupancy, $event) as $existingEventInstance)
			{
				if (!$this->canFindIntersections($event, $existingEventInstance))
				{
					continue;
				}

				$currentEventId = (int)($existingEventInstance['PARENT_ID'] ?? 0);
				$currentIndexByEventId[$currentEventId] = ($currentIndexByEventId[$currentEventId] ?? 0) + 1;
				$currentIndex = $currentIndexByEventId[$currentEventId];

				try
				{
					[$timestampFrom, $timestampTo] = $this->getEventTimestamps(
						$existingEventInstance,
						$existingEventOrigin,
					);
				}
				catch (ObjectException)
				{
					continue;
				}

				$this->timeline[$this->getTimelineKey($currentEventId, $currentIndex, false)] = $timestampFrom;
				$this->timeline[$this->getTimelineKey($currentEventId, $currentIndex, true)] = $timestampTo;
				$this->cachedEvents[$this->getCachedEventKey($currentEventId, $currentIndex)] = [
					'ID' => $currentEventId,
					'SECTION_ID' => (int)($existingEventInstance['SECTION_ID'] ?? $existingEventForOccupancy['SECTION_ID'] ?? 0),
					self::MATCHED_SECTION_IDS_FIELD => $existingEventInstance[self::MATCHED_SECTION_IDS_FIELD]
						?? $existingEventForOccupancy[self::MATCHED_SECTION_IDS_FIELD]
						?? null,
					'DATE_FROM' => $existingEventInstance['DATE_FROM'],
					'TZ_FROM' => $existingEventInstance['TZ_FROM'] ?? null,
				];
			}
		}

		$candidateOrigin = $this->getInstanceOrigin($event, self::EVENT_ORIGIN_CANDIDATE);
		$currentIndex = 1;
		foreach ($eventsForCheck as $eventForCheck)
		{
			if (empty($eventForCheck['DATE_FROM']) || empty($eventForCheck['DATE_TO']))
			{
				continue;
			}
			[$timestampFrom, $timestampTo] = $this->getEventTimestamps($eventForCheck, $candidateOrigin);

			$this->timeline[$this->getTimelineKey($this->checkEventId, $currentIndex, false)] = $timestampFrom;
			$this->timeline[$this->getTimelineKey($this->checkEventId, $currentIndex, true)] = $timestampTo;
			$this->cachedEvents[$this->getCachedEventKey($this->checkEventId, $currentIndex)] = [
				'ID' => $this->checkEventId,
				'DATE_FROM' => $eventForCheck['DATE_FROM'],
				'TZ_FROM' => $eventForCheck['TZ_FROM'] ?? null,
			];
			$currentIndex++;
		}
	}

	private function findBatchConflict(
		array $events,
		array $existingEvents,
		array $limitEvent,
		array $excludedEventIds,
	): ?array
	{
		$candidateIntervals = [];
		foreach ($events as $event)
		{
			$eventOrigin = $this->getInstanceOrigin($event, self::EVENT_ORIGIN_CANDIDATE);
			foreach ($this->getEventsForCheck($event) as $eventInstance)
			{
				if (empty($eventInstance['DATE_FROM']) || empty($eventInstance['DATE_TO']))
				{
					continue;
				}

				try
				{
					[$timestampFrom, $timestampTo] = $this->getEventTimestamps($eventInstance, $eventOrigin);
				}
				catch (ObjectException)
				{
					return $this->getInvalidBatchIntervalData();
				}

				$candidateIntervals[] = [
					'event' => $event,
					'from' => $timestampFrom,
					'to' => $timestampTo,
				];
			}
		}

		if ($candidateIntervals === [])
		{
			return null;
		}

		$existingIntervals = [];
		foreach ($existingEvents as $existingEvent)
		{
			if (isset($excludedEventIds[(int)($existingEvent['ID'] ?? 0)]))
			{
				continue;
			}

			$existingEventForOccupancy = $this->prepareEventForOccupancy($existingEvent);
			if ($existingEventForOccupancy === null)
			{
				continue;
			}

			$existingEventOrigin = $this->getInstanceOrigin(
				$existingEventForOccupancy,
				self::EVENT_ORIGIN_PERSISTED,
			);
			foreach ($this->getEventsForCheck($existingEventForOccupancy, $limitEvent) as $existingEventInstance)
			{
				if (empty($existingEventInstance['DATE_FROM']) || empty($existingEventInstance['DATE_TO']))
				{
					continue;
				}

				try
				{
					[$timestampFrom, $timestampTo] = $this->getEventTimestamps(
						$existingEventInstance,
						$existingEventOrigin,
					);
				}
				catch (ObjectException)
				{
					continue;
				}

				if (isset($existingEvent[self::MATCHED_SECTION_IDS_FIELD]))
				{
					$existingEventInstance[self::MATCHED_SECTION_IDS_FIELD] =
						$existingEvent[self::MATCHED_SECTION_IDS_FIELD]
					;
				}
				$existingIntervals[] = [
					'event' => $existingEventInstance,
					'from' => $timestampFrom,
					'to' => $timestampTo,
				];
			}
		}

		$candidateParentIds = [];
		foreach ($candidateIntervals as $candidateInterval)
		{
			$event = $candidateInterval['event'];
			$parentId = (int)($event['PARENT_ID'] ?? $event['ID'] ?? 0);
			if ($parentId > 0)
			{
				$candidateParentIds[$parentId] = true;
			}
		}

		if (count($candidateParentIds) === 1)
		{
			$candidateParentId = (int)array_key_first($candidateParentIds);
			$existingIntervals = array_values(array_filter(
				$existingIntervals,
				static fn(array $interval): bool => (int)($interval['event']['PARENT_ID'] ?? 0) !== $candidateParentId,
			));
		}

		if ($existingIntervals === [])
		{
			return null;
		}

		$sortIntervals = static function (array $left, array $right): int {
			return $left['from'] <=> $right['from'] ?: $left['to'] <=> $right['to'];
		};

		usort($candidateIntervals, $sortIntervals);
		usort($existingIntervals, $sortIntervals);

		$disturbingEventDates = [];
		$disturbingEventDatesUtc = [];
		$disturbingEventSectionIds = [];
		$isDisturbingEventsAmountOverShowLimit = false;
		$candidateIndex = 0;
		$existingIndex = 0;
		$candidateCount = count($candidateIntervals);
		$existingCount = count($existingIntervals);
		while ($candidateIndex < $candidateCount && $existingIndex < $existingCount)
		{
			$candidateInterval = $candidateIntervals[$candidateIndex];
			$existingInterval = $existingIntervals[$existingIndex];

			if ($candidateInterval['to'] < $existingInterval['from'])
			{
				$candidateIndex++;
				continue;
			}

			if ($existingInterval['to'] < $candidateInterval['from'])
			{
				$existingIndex++;
				continue;
			}

			if ($this->canFindIntersections($candidateInterval['event'], $existingInterval['event']))
			{
				if (count($disturbingEventDates) >= self::DISTURBING_EVENTS_LIMIT)
				{
					$isDisturbingEventsAmountOverShowLimit = true;
					break;
				}

				$disturbingEvent = $existingInterval['event'];
				$sectionIds = $disturbingEvent[self::MATCHED_SECTION_IDS_FIELD] ?? [
					(int)($disturbingEvent['SECTION_ID'] ?? 0),
				];
				foreach ($sectionIds as $sectionId)
				{
					$sectionId = (int)$sectionId;
					if ($sectionId > 0)
					{
						$disturbingEventSectionIds[$sectionId] = $sectionId;
					}
				}

				$disturbingEventDate = $this->getEventFormattedDate($disturbingEvent);
				$disturbingEventDateUtc = $this->getEventDateUtc($disturbingEvent);
				if (!in_array($disturbingEventDate, $disturbingEventDates, true))
				{
					$disturbingEventDates[] = $disturbingEventDate;
					if ($disturbingEventDateUtc !== null)
					{
						$disturbingEventDatesUtc[] = $disturbingEventDateUtc;
					}
				}
			}

			if ($existingInterval['to'] <= $candidateInterval['to'])
			{
				$existingIndex++;
			}
			else
			{
				$candidateIndex++;
			}
		}

		if ($disturbingEventDates === [])
		{
			return null;
		}

		return [
			'disturbingEventsFormatted' => implode(', ', $disturbingEventDates),
			'isDisturbingEventsAmountOverShowLimit' => $isDisturbingEventsAmountOverShowLimit,
			'disturbingEventsUtc' => $disturbingEventDatesUtc,
			'disturbingEventSectionIds' => array_values($disturbingEventSectionIds),
		];
	}

	private function findCandidateConflict(array $eventsByRoom): ?array
	{
		$candidateCount = array_sum(array_map('count', $eventsByRoom));
		if ($candidateCount < 2)
		{
			return null;
		}

		$additionalSectionIdsByRoom = AccessibilityManager::getFullAdditionalLocationAccessibilityConnection();
		$candidateIntervals = [];
		$candidateIndex = 0;
		foreach ($eventsByRoom as $roomId => $events)
		{
			$sectionIds = array_fill_keys(
				$this->getRoomSectionIds((int)$roomId, $additionalSectionIdsByRoom[$roomId] ?? []),
				true,
			);
			foreach ($events as $event)
			{
				$candidateIndex++;
				$parentId = (int)($event['PARENT_ID'] ?? $event['ID'] ?? 0);
				$candidateKey = $parentId > 0 ? 'event:' . $parentId : 'candidate:' . $candidateIndex;
				$eventOrigin = $this->getInstanceOrigin($event, self::EVENT_ORIGIN_CANDIDATE);
				foreach ($this->getEventsForCheck($event) as $eventInstance)
				{
					if (empty($eventInstance['DATE_FROM']) || empty($eventInstance['DATE_TO']))
					{
						continue;
					}

					try
					{
						[$timestampFrom, $timestampTo] = $this->getEventTimestamps(
							$eventInstance,
							$eventOrigin,
						);
					}
					catch (ObjectException)
					{
						return $this->getInvalidBatchIntervalData();
					}

					$candidateIntervals[] = [
						'candidateKey' => $candidateKey,
						'event' => $eventInstance,
						'from' => $timestampFrom,
						'to' => $timestampTo,
						'sectionIds' => $sectionIds,
					];
				}
			}
		}

		usort(
			$candidateIntervals,
			static fn(array $left, array $right): int => $left['from'] <=> $right['from'] ?: $left['to'] <=> $right['to'],
		);
		$activeIntervals = [];
		foreach ($candidateIntervals as $candidateInterval)
		{
			foreach ($activeIntervals as $key => $activeInterval)
			{
				if ($activeInterval['to'] < $candidateInterval['from'])
				{
					unset($activeIntervals[$key]);
					continue;
				}
				$matchedSectionIds = array_intersect_key(
					$activeInterval['sectionIds'],
					$candidateInterval['sectionIds'],
				);
				if (
					$activeInterval['candidateKey'] === $candidateInterval['candidateKey']
					|| $matchedSectionIds === []
				)
				{
					continue;
				}

				$disturbingEvent = $candidateInterval['event'];
				$disturbingEventDateUtc = $this->getEventDateUtc($disturbingEvent);

				return [
					'disturbingEventsFormatted' => $this->getEventFormattedDate($disturbingEvent),
					'isDisturbingEventsAmountOverShowLimit' => false,
					'disturbingEventsUtc' => $disturbingEventDateUtc === null ? [] : [$disturbingEventDateUtc],
					'disturbingEventSectionIds' => array_map('intval', array_keys($matchedSectionIds)),
				];
			}

			$activeIntervals[] = $candidateInterval;
		}

		return null;
	}

	/**
	 * @param array $event
	 * @param array $existingEvent
	 * @return bool
	 */
	private function canFindIntersections(array $event, array $existingEvent): bool
	{
		$eventParentId = (int)($event['PARENT_ID'] ?? $event['ID'] ?? 0);
		$existingParentId = (int)($existingEvent['PARENT_ID'] ?? 0);

		return
			$eventParentId !== $existingParentId
			&& !empty($existingEvent['PARENT_ID'])
			&& !empty($existingEvent['DATE_FROM'])
			&& !empty($existingEvent['DATE_TO'])
			&& !empty($existingEvent['ID'])
		;
	}

	/**
	 * @param int $eventId
	 * @param int $repeatNumber
	 * @param bool $isEnd
	 * @return string
	 */
	private function getTimelineKey(int $eventId, int $repeatNumber, bool $isEnd): string
	{
		return $eventId . '_' . $repeatNumber . '_' . ($isEnd ? self::KEY_PART_END : self::KEY_PART_START);
	}

	/**
	 * @param string $key
	 * @return array
	 */
	private function parseTimelineKey(string $key): array
	{
		$res =  explode('_', $key);
		return [(int)$res[0], (int)$res[1], ($res[2] === self::KEY_PART_END)];
	}

	/**
	 * @param int $eventId
	 * @param int $repeatNumber
	 * @return string
	 */
	private function getCachedEventKey(int $eventId, int $repeatNumber): string
	{
		return $eventId . '_' . $repeatNumber;
	}

	/**
	 * @param array $event
	 * @return string
	 */
	private function getEventFormattedDate(array $event): string
	{
		$eventTimezone = null;
		if (!empty($event['TZ_FROM']))
		{
			$eventTimezone = new \DateTimeZone($event['TZ_FROM']);
		}
		$result = '';
		try
		{
			$result = \Bitrix\Calendar\Util::formatEventDate(
				new DateTime($event['DATE_FROM'], null, $eventTimezone)
			);
		}
		catch (ObjectException $e)
		{
		}

		return $result;
	}

	private function getEventDateUtc(array $event): ?string
	{
		if (empty($event['DATE_FROM']))
		{
			return null;
		}

		try
		{
			$timezone = ($event['DT_SKIP_TIME'] ?? null) === 'Y'
				? new \DateTimeZone('UTC')
				: (!empty($event['TZ_FROM']) ? new \DateTimeZone($event['TZ_FROM']) : null)
			;
			$timestamp = (new DateTime($event['DATE_FROM'], null, $timezone))->getTimestamp();

			return $timestamp > 0 ? gmdate('Y-m-d\\TH:i:s\\Z', $timestamp) : null;
		}
		catch (ObjectException)
		{
			return null;
		}
	}
}
