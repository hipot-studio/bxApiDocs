<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Util;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Forum\TopicTable;
use Bitrix\Forum\MessageTable;
use Bitrix\Im\Model\ChatTable;

class CBPCalendarGetInform extends BaseActivity implements IBPConfigurableActivity
{
	private const CALENDAR_TYPE_USER = 'user';
	private const CALENDAR_TYPE_GROUP = 'group';
	private const CALENDAR_USER = 'CalendarUser';
	private const CALENDAR_GROUP = 'CalendarGroup';
	private const CALENDAR_FROM = 'CalendarFrom';
	private const CALENDAR_TO = 'CalendarTo';
	private const EVENT_FIELD = 'EventField';
	private const RETURN_PARAM_JSON = 'ResultJson';
	private const RETURN_PARAM_AI = 'ResultJsonAi';
	private const RETURN_EVENTS_COUNT = 'EventsCount';
	private const RETURN_PARAM_TITLES_LIST = 'EVENTS_TITLES_LIST';
	private const RETURN_PARAM_TITLES_BB_LIST = 'EVENTS_TITLES_BB_LIST';
	private const RETURN_MEETINGS_TOTAL_MINUTES = 'MeetingsTotalMinutes';
	private const EVENT_FIELD_DESCRIPTION = 'description';
	private const EVENT_FIELD_LOCATION = 'location';
	private const EVENT_FIELD_ATTENDEES = 'attendees';
	private const EVENT_FIELD_COMMENTS = 'comments';
	private const EVENT_FIELD_CHAT = 'chat';
	private const EVENT_FIELD_URL = 'url';

	protected static $requiredModules = ['calendar'];

	/** @var int|null Computed inside fetchEvents(); consumed by internalExecute(). */
	private ?int $meetingsTotalMinutes = null;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			self::CALENDAR_USER => '',
			self::CALENDAR_GROUP => null,
			self::CALENDAR_FROM => '',
			self::CALENDAR_TO => '',
			self::EVENT_FIELD => null,

			self::RETURN_PARAM_JSON => '',
			self::RETURN_PARAM_AI => '',
			self::RETURN_EVENTS_COUNT => '',
			self::RETURN_PARAM_TITLES_LIST => null,
			self::RETURN_PARAM_TITLES_BB_LIST => null,
			self::RETURN_MEETINGS_TOTAL_MINUTES => null,
		];

		$this->setPropertiesTypes(
			[
				self::CALENDAR_USER => ['Type' => FieldType::USER,],
				self::CALENDAR_GROUP => ['Type' => FieldType::ENTITYSELECTOR],
				self::CALENDAR_FROM => ['Type' => FieldType::DATETIME,],
				self::CALENDAR_TO => ['Type' => FieldType::DATETIME,],
				self::EVENT_FIELD => ['Type' => FieldType::SELECT],
				self::RETURN_PARAM_JSON  => ['Type' => FieldType::STRING],
				self::RETURN_PARAM_AI => ['Type' => FieldType::STRING],
				self::RETURN_EVENTS_COUNT => ['Type' => FieldType::INT],
				self::RETURN_PARAM_TITLES_LIST => ['Type' => FieldType::TEXT],
				self::RETURN_PARAM_TITLES_BB_LIST => ['Type' => FieldType::TEXT],
				self::RETURN_MEETINGS_TOTAL_MINUTES => ['Type' => FieldType::INT],
			],
		);
	}

	protected function reInitialize(): void
	{
		$this->{self::RETURN_PARAM_JSON} = null;
		$this->{self::RETURN_PARAM_AI} = null;
		$this->{self::RETURN_EVENTS_COUNT} = null;
		$this->{self::RETURN_PARAM_TITLES_LIST} = null;
		$this->{self::RETURN_PARAM_TITLES_BB_LIST} = null;
		$this->{self::RETURN_MEETINGS_TOTAL_MINUTES} = null;
		$this->meetingsTotalMinutes = null;

		parent::reInitialize();
	}

	protected function internalExecute(): ErrorCollection
	{
		$errors = new ErrorCollection();
		$availableEventFields = array_keys(self::getEventFieldOptions());
		$select = $this->{self::EVENT_FIELD} ?? $availableEventFields;
		$select = array_intersect($availableEventFields, (array)$select);

		$fromTs = CBPHelper::makeTimestamp($this->{self::CALENDAR_FROM});
		$toTs = CBPHelper::makeTimestamp($this->{self::CALENDAR_TO});

		$maxDuration = 31 * 24 * 3600;
		if (($toTs - $fromTs) > $maxDuration)
		{
			$toTs = $fromTs + $maxDuration;
		}

		$userId = (int)CBPHelper::extractFirstUser($this->{self::CALENDAR_USER}, $this->getDocumentId());
		$groupId = (int)$this->{self::CALENDAR_GROUP};

		if ($groupId)
		{
			$calendarType = self::CALENDAR_TYPE_GROUP;
			$ownerId = $groupId;
		}
		elseif ($userId)
		{
			$calendarType = self::CALENDAR_TYPE_USER;
			$ownerId = $userId;
		}
		else
		{
			$errors->add([
				new \Bitrix\Main\Error(Loc::getMessage('BPSNMA_PD_ERROR_EMPTY_SOURCE')),
			]);

			return $errors;
		}

		$results = $this->fetchEvents($ownerId, $calendarType, $fromTs, $toTs, $select, $userId);

		$this->{self::RETURN_PARAM_JSON} = Json::encode($results);
		$this->{self::RETURN_PARAM_AI} = Json::encode(
			$this->prepareResultsForAi($results),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		);
		$this->{self::RETURN_EVENTS_COUNT} = count($results);

		[$titles, $bbTitles] = $this->buildTitleLists($results);
		$this->{self::RETURN_PARAM_TITLES_LIST} = implode("\n", $titles);
		$this->{self::RETURN_PARAM_TITLES_BB_LIST} = implode("\n", $bbTitles);

		if ($this->meetingsTotalMinutes !== null)
		{
			$this->{self::RETURN_MEETINGS_TOTAL_MINUTES} = $this->meetingsTotalMinutes;
		}

		return $errors;
	}

	private function buildTitleLists(array $events): array
	{
		$titles = [];
		$bbTitles = [];

		foreach ($events as $event)
		{
			$name = (string)($event['name'] ?? '');
			$url = (string)($event['url'] ?? '');
			$prefix = $this->formatEventTimePrefix($event);

			$plain = $prefix === '' ? $name : $prefix . ' ' . $name;
			$linked = $url !== '' ? '[url=' . $url . ']' . $name . '[/url]' : $name;

			$titles[] = '- ' . $plain;
			$bbTitles[] = '- ' . ($prefix === '' ? $linked : $prefix . ' ' . $linked);
		}

		return [$titles, $bbTitles];
	}

	private function formatEventTimePrefix(array $event): string
	{
		$dateFrom = (string)($event['dateFrom'] ?? '');
		$dateTo = (string)($event['dateTo'] ?? '');

		if ($dateFrom === '' || !str_contains($dateFrom, 'T'))
		{
			return '';
		}

		$from = (new \DateTimeImmutable($dateFrom))->format('H:i');
		$to = $dateTo !== '' ? (new \DateTimeImmutable($dateTo))->format('H:i') : '';

		return $to !== '' ? "{$from} - {$to}" : $from;
	}

	protected function prepareProperties(): void {}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::CALENDAR_USER => [
				'Name' => Loc::getMessage('BPSNMA_PD_CUSER'),
				'FieldName' => self::CALENDAR_USER,
				'Type' => FieldType::USER,
			],
			self::CALENDAR_GROUP => [
				'Name' => Loc::getMessage('BPSNMA_PD_CGROUP'),
				'FieldName' => self::CALENDAR_GROUP,
				'Type' => FieldType::ENTITYSELECTOR,
				'Settings' => [
					'entity' => ['id' => 'project'],
				],
			],
			self::CALENDAR_FROM => [
				'Name' => Loc::getMessage('BPSNMA_PD_CFROM'),
				'FieldName' => self::CALENDAR_FROM,
				'Type' => FieldType::DATETIME,
				'Required' => true,
			],
			self::CALENDAR_TO => [
				'Name' => Loc::getMessage('BPSNMA_PD_CTO'),
				'FieldName' => self::CALENDAR_TO,
				'Type' => FieldType::DATETIME,
				'Required' => true,
			],
			self::EVENT_FIELD => [
				'Name' => Loc::getMessage('BPSNMA_PD_EVENT_FIELD'),
				'FieldName' => self::EVENT_FIELD,
				'Type' => FieldType::SELECT,
				'Options' => self::getEventFieldOptions(),
				'Default' => array_keys(self::getEventFieldOptions()),
				'Multiple' => true,
			],
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	private function fetchEvents(
		int $ownerId,
		string $calendarType,
		int $fromTs,
		int $toTs,
		array $select,
		int $viewerId = 0,
	): array
	{
		$dateFrom = Util::formatDateTimeTimestampUTC($fromTs);
		$dateTo = Util::formatDateTimeTimestampUTC($toTs - \CCalendar::GetDayLen());

		$events = \CCalendarEvent::GetList(
			[
				'arFilter' => [
					'FROM_LIMIT' => $dateFrom,
					'TO_LIMIT' => $dateTo,
					'CAL_TYPE' => [Dictionary::CALENDAR_TYPE[$calendarType]],
					'OWNER_ID' => [$ownerId],
					'ACTIVE_SECTION' => 'Y',
				],
				'parseRecursion' => true,
				'preciseLimits' => true,
				'fetchAttendees' => in_array(self::EVENT_FIELD_ATTENDEES, $select, true),
				'fetchSection' => true,
				'setDefaultLimit' => false,
				'checkPermissions' => false,
				'userId' => $viewerId,
			],
		);

		$uniqueEvents = [];
		foreach ($events as $event)
		{
			$key = $event['ID'] . '|' . ($event['DATE_FROM'] ?? '');
			if (!isset($uniqueEvents[$key]))
			{
				$uniqueEvents[$key] = $event;
			}
		}
		$events = array_values($uniqueEvents);
		$events = array_filter($events, fn(array $event) => !$this->isDeclinedMeeting($event));

		usort(
			$events,
			fn(array $a, array $b) => $this->getEventStartTimestamp($a) <=> $this->getEventStartTimestamp($b),
		);

		$this->meetingsTotalMinutes = $this->computeMeetingsTotalMinutes($events);

		return $this->formatEvents($events, $select, $calendarType, $viewerId);
	}

	private function getEventStartTimestamp(array $event): int
	{
		$date = $this->mapEventDateToObject(
			$event['DATE_FROM'] ?? null,
			$event['TZ_FROM'] ?? null,
			($event['DT_SKIP_TIME'] ?? null) === 'Y',
		);

		return $date ? $date->getTimestamp() : 0;
	}

	private function formatEvents(
		array $events,
		array $select,
		string $calType = self::CALENDAR_TYPE_USER,
		int $viewerId = 0,
	): array
	{
		$result = [];

		$viewerTimezone = $viewerId
			? Util::prepareTimezone(\CCalendar::GetUserTimezoneName($viewerId))
			: null
		;

		$selectAttendees = in_array(self::EVENT_FIELD_ATTENDEES, $select, true);
		$selectComments = in_array(self::EVENT_FIELD_COMMENTS, $select, true);
		$selectChats = in_array(self::EVENT_FIELD_CHAT, $select, true);
		$selectUrl = in_array(self::EVENT_FIELD_URL, $select, true);
		$selectDescription = in_array(self::EVENT_FIELD_DESCRIPTION, $select, true);
		$selectLocation = in_array(self::EVENT_FIELD_LOCATION, $select, true);

		$eventsAttendees = $selectAttendees ? $this->formatAttendees($events) : [];
		$eventsComments = $selectComments ? $this->fetchCommentsForEvents($events) : [];
		$eventsChats = $selectChats ? $this->fetchChatsForEvents($events) : [];

		foreach ($events as $key => $event)
		{
			$isFullDayEvent = ($event['DT_SKIP_TIME'] ?? null) === 'Y';

			$dateFrom = $this->mapEventDateToObject(
				$event['DATE_FROM'] ?? null,
				$event['TZ_FROM'] ?? null,
				$isFullDayEvent,
			);

			$dateTo = $this->mapEventDateToObject(
				$event['DATE_TO'] ?? null,
				$event['TZ_TO'] ?? null,
				$isFullDayEvent,
			);

			if ($dateTo && $isFullDayEvent)
			{
				$dateTo->add('+1 day');
			}

			if (!$isFullDayEvent && $viewerTimezone)
			{
				if ($dateFrom instanceof DateTime)
				{
					$dateFrom->setTimeZone($viewerTimezone);
				}
				if ($dateTo instanceof DateTime)
				{
					$dateTo->setTimeZone($viewerTimezone);
				}
			}

			$item = [
				'id' => $event['ID'],
				'name' => $event['NAME'],
				'dateFrom' => $dateFrom?->format($isFullDayEvent ? 'Y-m-d' : 'c'),
				'dateTo' => $dateTo?->format($isFullDayEvent ? 'Y-m-d' : 'c'),
				'ownerId' => $event['OWNER_ID'] ?? null,
				'isMeeting' => (bool)($event['IS_MEETING'] ?? false),
				'meetingStatus' => $event['MEETING_STATUS'] ?? null,
			];

			if ($selectDescription)
			{
				$item['description'] = $event['DESCRIPTION'] ?? null;
			}

			if ($selectLocation)
			{
				$item['location'] = $event['LOCATION'] ?? null;
			}

			if ($selectAttendees)
			{
				$item['attendees'] = $eventsAttendees[$key] ?? [];
			}

			if ($selectComments)
			{
				$item['comments'] = $eventsComments[$event['ID']] ?? [];
			}

			if ($selectChats)
			{
				$item['chat'] = $eventsChats[$event['ID']] ?? null;
			}

			if ($selectUrl)
			{
				$ownerId = (int)($event['OWNER_ID'] ?? 0);
				$id = (int)($event['ID'] ?? 0);
				$dateFrom = (string)($event['DATE_FROM'] ?? '');

				$item['url'] = CCalendar::getEntryUrl($calType, $ownerId, $id, $dateFrom);
			}

			$result[] = $item;
		}

		return $result;
	}

	private function isDeclinedMeeting(array $event): bool
	{
		return !empty($event['IS_MEETING']) && ($event['MEETING_STATUS'] ?? '') === 'N';
	}

	/**
	 * Returns true if the event counts as a "real meeting" for time-on-meetings accounting.
	 *
	 * Exclusion criteria (INVARIANT — do not change without task specification):
	 *  - All-day events (DT_SKIP_TIME === 'Y')
	 *  - Events where the user is free or absent (ACCESSIBILITY ∈ {'free', 'absent'})
	 *  - Meeting events where the user declined or is undecided (MEETING_STATUS ∈ {'N', 'Q'})
	 *
	 * Included: confirmed meetings (MEETING_STATUS === 'Y'), host/owner events (MEETING_STATUS === 'H'),
	 * and regular non-meeting events that are not free/absent/all-day.
	 */
	private function isRealMeeting(array $event): bool
	{
		if (($event['DT_SKIP_TIME'] ?? null) === 'Y')
		{
			return false;
		}

		$accessibility = $event['ACCESSIBILITY'] ?? '';
		if ($accessibility === 'free' || $accessibility === 'absent')
		{
			return false;
		}

		if (!empty($event['IS_MEETING']))
		{
			$meetingStatus = $event['MEETING_STATUS'] ?? '';
			if ($meetingStatus === 'N' || $meetingStatus === 'Q')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Extracts the UTC timestamp interval [fromTs, toTs) for a raw calendar event.
	 *
	 * Primary source: DATE_FROM / DATE_TO strings parsed with their timezone offsets via
	 * mapEventDateToObject(). This gives the correct per-instance UTC time for recurrent
	 * event instances produced by CCalendarEvent::ParseRecursion(), whose DATE_FROM_TS_UTC /
	 * DATE_TO_TS_UTC fields are not updated and retain the master-event timestamps.
	 *
	 * Fallback: DATE_FROM_TS_UTC / DATE_TO_TS_UTC (used when string date fields are absent).
	 *
	 * @return array{0: int, 1: int}|null  [fromTs, toTs] in UTC seconds, or null if dates cannot be resolved.
	 */
	private function getEventUtcInterval(array $event): ?array
	{
		$dateFrom = $this->mapEventDateToObject(
			$event['DATE_FROM'] ?? null,
			$event['TZ_FROM'] ?? null,
			false,
		);
		$fromTs = $dateFrom !== null
			? $dateFrom->getTimestamp()
			: (isset($event['DATE_FROM_TS_UTC']) ? (int)$event['DATE_FROM_TS_UTC'] : null);

		if ($fromTs === null)
		{
			return null;
		}

		$dateTo = $this->mapEventDateToObject(
			$event['DATE_TO'] ?? null,
			$event['TZ_TO'] ?? null,
			false,
		);
		$toTs = $dateTo !== null
			? $dateTo->getTimestamp()
			: (isset($event['DATE_TO_TS_UTC']) ? (int)$event['DATE_TO_TS_UTC'] : null);

		if ($toTs === null)
		{
			return null;
		}

		return [$fromTs, $toTs];
	}

	/**
	 * Computes the total meeting minutes for the given raw events using interval merge (ALG-01).
	 * Returns null if there are no qualifying meetings with non-zero duration.
	 */
	private function computeMeetingsTotalMinutes(array $rawEvents): ?int
	{
		$intervals = [];

		foreach ($rawEvents as $event)
		{
			if (!$this->isRealMeeting($event))
			{
				continue;
			}

			$interval = $this->getEventUtcInterval($event);
			if ($interval === null)
			{
				continue;
			}

			[$fromTs, $toTs] = $interval;
			if ($toTs <= $fromTs)
			{
				continue;
			}

			$intervals[] = [$fromTs, $toTs];
		}

		if (empty($intervals))
		{
			return null;
		}

		$minutes = $this->mergeIntervalsToMinutes($intervals);

		return $minutes > 0 ? $minutes : null;
	}

	/**
	 * Merges overlapping/adjacent intervals and returns the total duration in whole minutes (ALG-01).
	 *
	 * @param array<array{0: int, 1: int}> $intervals  List of [fromTs, toTs] pairs in UTC seconds.
	 */
	private function mergeIntervalsToMinutes(array $intervals): int
	{
		usort($intervals, static fn(array $a, array $b) => $a[0] <=> $b[0]);

		$mergedSeconds = 0;
		$mergeStart    = null;
		$mergeEnd      = null;

		foreach ($intervals as [$from, $to])
		{
			if ($to <= $from)
			{
				continue;
			}

			if ($mergeStart === null)
			{
				$mergeStart = $from;
				$mergeEnd   = $to;
			}
			elseif ($from <= $mergeEnd)
			{
				if ($to > $mergeEnd)
				{
					$mergeEnd = $to;
				}
			}
			else
			{
				$mergedSeconds += $mergeEnd - $mergeStart;
				$mergeStart = $from;
				$mergeEnd   = $to;
			}
		}

		if ($mergeStart !== null)
		{
			$mergedSeconds += $mergeEnd - $mergeStart;
		}

		return intdiv($mergedSeconds, 60);
	}

	private function formatAttendees(array $events): array
	{
		$attendeeIds = $this->getUniqueAttendeeIds($events);

		$userNames = $this->getUserNames($attendeeIds);

		return $this->formatEventAttendees($events, $userNames);
	}

	private function getUniqueAttendeeIds(array $events): array
	{
		$attendeeIds = [];
		foreach ($events as $event)
		{
			if (isset($event['ATTENDEE_LIST']) && is_array($event['ATTENDEE_LIST']))
			{
				foreach ($event['ATTENDEE_LIST'] as $attendee)
				{
					if (isset($attendee['id']))
					{
						$attendeeIds[] = (int)$attendee['id'];
					}
				}
			}
		}

		return array_unique($attendeeIds);
	}

	private function formatEventAttendees(array $events, array $userNames): array
	{
		$result = [];

		$participationStatus = [
			'H' => 'Host',
			'Q' => 'Invited',
			'Y' => 'Accepted',
			'N' => 'Declined',
		];

		foreach ($events as $key => $event)
		{
			$attendees = [];
			$attendeeList = $event['ATTENDEE_LIST'] ?? [];

			if (is_array($attendeeList))
			{
				foreach ($attendeeList as $attendee)
				{
					$attendees[] = [
						'id' => $attendee['id'] ?? null,
						'name' => $userNames[$attendee['id']] ?? null,
						'participationStatus' => !empty($attendee['status'])
							? $participationStatus[$attendee['status']] : null,
					];
				}
			}
			$result[$key] = $attendees;
		}

		return $result;
	}

	private function fetchCommentsForEvents(array $events): array
	{
		if (!Loader::includeModule('forum'))
		{
			return [];
		}

		$forumId = (int)(\CCalendar::GetSettings()['forum_id'] ?? null);
		if (!$forumId)
		{
			return [];
		}

		$eventMap = $this->getEventXmlIds($events);
		if (empty($eventMap))
		{
			return [];
		}

		$topics = $this->fetchTopics($forumId, $eventMap);
		if (empty($topics))
		{
			return [];
		}

		$topicMap = array_column($topics, 'XML_ID', 'ID');
		if (empty($topicMap))
		{
			return [];
		}

		$messages = $this->fetchMessages($forumId, array_keys($topicMap));
		if (empty($messages))
		{
			return [];
		}

		return $this->mapMessagesToEvents($messages, $topicMap, $eventMap);
	}

	private function getEventXmlIds(array $events): array
	{
		$eventMap = [];
		foreach ($events as $event)
		{
			$xmlId = !empty($event['RELATIONS']['COMMENT_XML_ID'])
				? $event['RELATIONS']['COMMENT_XML_ID']
				: 'EVENT_' . ($event['PARENT_ID'] ? : $event['ID']);

			if ($xmlId)
			{
				$eventMap[$event['ID']] = $xmlId;
			}
		}

		return $eventMap;
	}

	private function fetchTopics(int $forumId, array $xmlIds): array
	{
		return TopicTable::query()
			->setSelect(['ID', 'XML_ID'])
			->where('FORUM_ID', $forumId)
			->whereIn('XML_ID', array_values($xmlIds))
			->exec()
			->fetchAll()
		;
	}

	private function fetchMessages(int $forumId, array $topicIds): array
	{
		return MessageTable::query()
			->setSelect(['ID', 'TOPIC_ID', 'AUTHOR_ID', 'AUTHOR_NAME', 'POST_MESSAGE', 'POST_DATE'])
			->where('FORUM_ID', $forumId)
			->whereIn('TOPIC_ID', $topicIds)
			->whereNotNull('AUTHOR_ID')
			->setOrder(['ID' => 'ASC'])
			->exec()
			->fetchAll()
		;
	}

	private function mapMessagesToEvents(array $messages, array $topicMap, array $eventMap): array
	{
		$commentsByXmlId = [];
		foreach ($messages as $message)
		{
			$xmlId = $topicMap[$message['TOPIC_ID']] ?? null;
			if (!$xmlId)
			{
				continue;
			}

			$postDate = $message['POST_DATE'];
			if ($postDate instanceof DateTime)
			{
				$postDate = $postDate->toString();
			}

			$commentsByXmlId[$xmlId][] = [
				'id' => $message['ID'],
				'authorId' => $message['AUTHOR_ID'],
				'authorName' => $message['AUTHOR_NAME'],
				'postMessage' => $message['POST_MESSAGE'],
				'postDate' => $postDate,
			];
		}

		$result = [];
		foreach ($eventMap as $eventId => $xmlId)
		{
			if (isset($commentsByXmlId[$xmlId]))
			{
				$result[$eventId] = $commentsByXmlId[$xmlId];
			}
		}

		return $result;
	}

	private function fetchChatsForEvents(array $events): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		$chatIds = $this->getEventChatIds($events);
		if (empty($chatIds))
		{
			return [];
		}

		$chatsData = $this->fetchChats(array_unique(array_values($chatIds)));

		return $this->mapChatsToEvents($chatIds, $chatsData);
	}

	private function getEventChatIds(array $events): array
	{
		$chatIds = [];
		foreach ($events as $event)
		{
			if (!empty($event['MEETING']['CHAT_ID']))
			{
				$chatIds[$event['ID']] = (int)$event['MEETING']['CHAT_ID'];
			}
		}

		return $chatIds;
	}

	private function fetchChats(array $chatIds): array
	{
		$chatsData = [];

		$chats = ChatTable::query()
			->setSelect(['ID', 'TITLE', 'TYPE', 'AUTHOR_ID'])
			->whereIn('ID', $chatIds)
			->exec()
			->fetchAll()
		;

		foreach ($chats as $chat)
		{
			$chatsData[$chat['ID']] = [
				'chatId' => (int)$chat['ID'],
				'title' => $chat['TITLE'],
				'type' => $chat['TYPE'],
				'ownerId' => (int)$chat['AUTHOR_ID'],
			];
		}

		return $chatsData;
	}

	private function mapChatsToEvents(array $chatIds, array $chatsData): array
	{
		$result = [];
		foreach ($chatIds as $eventId => $chatId)
		{
			if (isset($chatsData[$chatId]))
			{
				$result[$eventId] = $chatsData[$chatId];
			}
			else
			{
				$result[$eventId] = ['chatId' => $chatId];
			}
		}

		return $result;
	}

	private function mapEventDateToObject(
		?string $date,
		?string $timeZone,
		?bool $isFullDayEvent,
	): ?Date
	{
		if (!$date)
		{
			return null;
		}

		try
		{
			$dateObject = Util::getDateObject(
				$date,
				$isFullDayEvent,
				$timeZone,
			);
		}
		catch (\Bitrix\Main\ObjectException $e)
		{
			return null;
		}

		return $dateObject;
	}

	private function prepareResultsForAi(array $results): array
	{
		if (empty($results))
		{
			return [];
		}

		$cleanResults = [];

		foreach ($results as $event)
		{
			$cleanResults[] = $this->cleanEventForAi($event);
		}

		return $cleanResults;
	}

	private function getUserNames(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$names = [];
		$res = \Bitrix\Main\UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME'])
			->whereIn('ID', $userIds)
			->exec()
		;

		while ($user = $res->fetch())
		{
			$names[$user['ID']] = \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false);
		}

		return $names;
	}

	private function cleanEventForAi(array $event): array
	{
		$cleanEvent = [
			'name' => $event['name'],
			'dateFrom' => $event['dateFrom'],
			'dateTo' => $event['dateTo'],
		];

		if (!empty($event['description']))
		{
			$cleanEvent['description'] = $event['description'];
		}

		if (!empty($event['location']))
		{
			$cleanEvent['location'] = $event['location'];
		}

		if (!empty($event['attendees']))
		{
			foreach ($event['attendees'] as $attendee)
			{
				$cleanEvent['attendees'][] = [
					'name' => $attendee['name'],
					'participationStatus' => $attendee['participationStatus'],
				];
			}
		}

		if (!empty($event['comments']))
		{
			foreach ($event['comments'] as $comment)
			{
				$cleanEvent['comments'][] = [
					'authorName' => $comment['authorName'],
					'postMessage' => $comment['postMessage'],
					'postDate' => $comment['postDate'],
				];
			}
		}

		if (!empty($event['chat']))
		{
			$cleanEvent['chat'] = [
				'title' => $event['chat']['title'] ?? null,
				'type' => $event['chat']['type'] ?? null,
			];
		}

		if (!empty($event['url']))
		{
			$cleanEvent['url'] = $event['url'];
		}


		return $cleanEvent;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	private static function getEventFieldOptions(): array
	{
		return [
			self::EVENT_FIELD_DESCRIPTION => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_DESCRIPTION'),
			self::EVENT_FIELD_LOCATION => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_LOCATION'),
			self::EVENT_FIELD_ATTENDEES => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_ATTENDEES'),
			self::EVENT_FIELD_COMMENTS => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_COMMENTS'),
			self::EVENT_FIELD_CHAT => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_CHAT'),
			self::EVENT_FIELD_URL => Loc::getMessage('BPSNMA_PD_EVENT_FIELD_URL'),
		];
	}

	public static function validateProperties($testProperties = [], \CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];
		if (empty($testProperties[self::CALENDAR_USER]) && empty($testProperties[self::CALENDAR_GROUP]))
		{
			$errors[] = [
				'message' => Loc::getMessage('BPSNMA_PD_ERROR_EMPTY_SOURCE'),
			];
		}

		return array_merge($errors, parent::ValidateProperties($testProperties, $user));
	}
}
