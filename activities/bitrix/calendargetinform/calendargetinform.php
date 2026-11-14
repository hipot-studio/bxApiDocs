<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Call\Public\Provider\MeetingCallParticipationProvider;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Util;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
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
	private const EVENT_LIMIT = 'EventLimit';
	private const CONFIRMED_CALL_PARTICIPATION = 'ConfirmedCallParticipation';
	private const ITEM_COLOR = 'ItemColor';
	private const OVERLAP_ICON_TYPE = 'OverlapIconType';
	private const OVERLAP_ICON_COLOR = 'OverlapIconColor';
	private const RETURN_PARAM_JSON = 'ResultJson';
	private const RETURN_PARAM_AI = 'ResultJsonAi';
	private const RETURN_EVENTS_COUNT = 'EventsCount';
	private const RETURN_PARAM_TITLES_LIST = 'EVENTS_TITLES_LIST';
	private const RETURN_PARAM_TITLES_BB_LIST = 'EVENTS_TITLES_BB_LIST';
	private const RETURN_PARAM_DETAILS_BB_LIST = 'EVENTS_DETAILS_BB_LIST';
	private const RETURN_PARAM_BLOCK_ITEMS_JSON = 'EVENTS_BLOCK_ITEMS_JSON';
	private const RETURN_EVENTS_HAVE_OVERLAPS = 'EventsHaveOverlaps';
	private const RETURN_SUCCEEDED = 'Succeeded';
	private const RETURN_MEETINGS_TOTAL_MINUTES = 'MeetingsTotalMinutes';
	private const RETURN_EVENTS_TRUNCATED = 'EventsTruncated';
	private const EVENT_LIMIT_MAX = 500;
	private const RAW_EVENT_LIMIT = 2000;
	private const RAW_EVENT_PAGE_SIZE = 100;
	private const EXPANDED_EVENT_LIMIT = 2000;
	private const FETCH_INTERVAL_SECONDS = 31 * 24 * 3600;
	private const FETCH_INTERVAL_LIMIT = 12;
	private const CALL_PARTICIPATED = 'participated';
	private const CALL_NOT_PARTICIPATED = 'notParticipated';
	private const CALL_PARTICIPATION_UNKNOWN = 'unknown';
	private const EVENT_FIELD_DESCRIPTION = 'description';
	private const EVENT_FIELD_LOCATION = 'location';
	private const EVENT_FIELD_ATTENDEES = 'attendees';
	private const EVENT_FIELD_COMMENTS = 'comments';
	private const EVENT_FIELD_CHAT = 'chat';
	private const EVENT_FIELD_URL = 'url';
	private const DISPLAY_TITLE_SEPARATOR = " \u{2014} ";
	private const DISPLAY_METADATA_SEPARATOR = " \u{00B7} ";
	private const OVERLAP_WARNING_ICON = "\u{26A0}";
	private const TEMPLATE_ONLY_INPUTS = [
		self::ITEM_COLOR,
		self::OVERLAP_ICON_TYPE,
		self::OVERLAP_ICON_COLOR,
		self::CONFIRMED_CALL_PARTICIPATION,
	];

	protected static $requiredModules = ['calendar'];

	/** @var int|null Computed inside fetchEvents(); consumed by internalExecute(). */
	private ?int $meetingsTotalMinutes = null;
	private bool $eventsTruncated = false;

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
			self::EVENT_LIMIT => null,
			self::CONFIRMED_CALL_PARTICIPATION => null,
			self::ITEM_COLOR => null,
			self::OVERLAP_ICON_TYPE => null,
			self::OVERLAP_ICON_COLOR => null,

			self::RETURN_PARAM_JSON => '',
			self::RETURN_PARAM_AI => '',
			self::RETURN_EVENTS_COUNT => '',
			self::RETURN_PARAM_TITLES_LIST => null,
			self::RETURN_PARAM_TITLES_BB_LIST => null,
			self::RETURN_PARAM_DETAILS_BB_LIST => null,
			self::RETURN_PARAM_BLOCK_ITEMS_JSON => null,
			self::RETURN_EVENTS_HAVE_OVERLAPS => false,
			self::RETURN_SUCCEEDED => false,
			self::RETURN_MEETINGS_TOTAL_MINUTES => null,
			self::RETURN_EVENTS_TRUNCATED => false,
		];

		$this->setPropertiesTypes(
			[
				self::CALENDAR_USER => ['Type' => FieldType::USER,],
				self::CALENDAR_GROUP => ['Type' => FieldType::ENTITYSELECTOR],
				self::CALENDAR_FROM => ['Type' => FieldType::DATETIME,],
				self::CALENDAR_TO => ['Type' => FieldType::DATETIME,],
				self::EVENT_FIELD => ['Type' => FieldType::SELECT],
				self::EVENT_LIMIT => ['Type' => FieldType::INT],
				self::CONFIRMED_CALL_PARTICIPATION => ['Type' => FieldType::BOOL],
				self::ITEM_COLOR => ['Type' => FieldType::STRING],
				self::OVERLAP_ICON_TYPE => ['Type' => FieldType::STRING],
				self::OVERLAP_ICON_COLOR => ['Type' => FieldType::STRING],
				self::RETURN_PARAM_JSON  => ['Type' => FieldType::STRING],
				self::RETURN_PARAM_AI => ['Type' => FieldType::STRING],
				self::RETURN_EVENTS_COUNT => ['Type' => FieldType::INT],
				self::RETURN_PARAM_TITLES_LIST => ['Type' => FieldType::TEXT],
				self::RETURN_PARAM_TITLES_BB_LIST => ['Type' => FieldType::TEXT],
				self::RETURN_PARAM_DETAILS_BB_LIST => ['Type' => FieldType::TEXT],
				self::RETURN_PARAM_BLOCK_ITEMS_JSON => ['Type' => FieldType::TEXT],
				self::RETURN_EVENTS_HAVE_OVERLAPS => ['Type' => FieldType::BOOL],
				self::RETURN_SUCCEEDED => ['Type' => FieldType::BOOL],
				self::RETURN_MEETINGS_TOTAL_MINUTES => ['Type' => FieldType::INT],
				self::RETURN_EVENTS_TRUNCATED => ['Type' => FieldType::BOOL],
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
		$this->{self::RETURN_PARAM_DETAILS_BB_LIST} = null;
		$this->{self::RETURN_PARAM_BLOCK_ITEMS_JSON} = null;
		$this->{self::RETURN_EVENTS_HAVE_OVERLAPS} = false;
		$this->{self::RETURN_SUCCEEDED} = false;
		$this->{self::RETURN_MEETINGS_TOTAL_MINUTES} = null;
		$this->{self::RETURN_EVENTS_TRUNCATED} = false;
		$this->meetingsTotalMinutes = null;
		$this->eventsTruncated = false;

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

		try
		{
			$eventLimit = $this->getEventLimit();
			if (($toTs - $fromTs) > self::FETCH_INTERVAL_SECONDS)
			{
				$toTs = $fromTs + self::FETCH_INTERVAL_SECONDS;
				$this->eventsTruncated = true;
			}

			$results = $eventLimit === null
				? $this->fetchEvents($ownerId, $calendarType, $fromTs, $toTs, $select, $userId)
				: $this->fetchLimitedEvents($ownerId, $calendarType, $fromTs, $toTs, $select, $userId, $eventLimit)
			;
		}
		catch (\Throwable)
		{
			$errors->setError(new \Bitrix\Main\Error((string)Loc::getMessage('BPSNMA_PD_ERROR_INVALID_CONTEXT')));

			return $errors;
		}

		$this->{self::RETURN_PARAM_JSON} = Json::encode($results);
		$this->{self::RETURN_PARAM_AI} = Json::encode(
			$this->prepareResultsForAi($results),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		);
		$this->{self::RETURN_EVENTS_COUNT} = count($results);
		$this->{self::RETURN_EVENTS_TRUNCATED} = $this->eventsTruncated;

		$overlapping = $this->findOverlappingEventIndexes($results);
		[$titles, $bbTitles] = $this->buildTitleLists($results, $overlapping);
		[, $displayBbTitles] = $this->buildDisplayTitleLists($results, $overlapping);
		$this->{self::RETURN_PARAM_TITLES_LIST} = implode("\n", $titles);
		$this->{self::RETURN_PARAM_TITLES_BB_LIST} = implode("\n", $bbTitles);
		$this->{self::RETURN_PARAM_DETAILS_BB_LIST} = implode("\n", $displayBbTitles);
		$this->{self::RETURN_PARAM_BLOCK_ITEMS_JSON} = Json::encode(
			$this->buildBlockItems($results, $overlapping),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		);
		$this->{self::RETURN_EVENTS_HAVE_OVERLAPS} = $overlapping !== [];
		$this->{self::RETURN_SUCCEEDED} = true;

		if ($this->meetingsTotalMinutes !== null)
		{
			$this->{self::RETURN_MEETINGS_TOTAL_MINUTES} = $this->meetingsTotalMinutes;
		}

		return $errors;
	}

	private function buildTitleLists(array $events, array $overlapping): array
	{
		$titles = [];
		$bbTitles = [];
		$overlapMark = $this->getTextOverlapMark();

		foreach ($events as $index => $event)
		{
			$name = (string)($event['name'] ?? '');
			$bbName = $this->neutralizeMessageText($name);
			$url = (string)($event['url'] ?? '');
			$prefix = $this->formatEventTimePrefix($event);

			$plain = $prefix === '' ? $name : $prefix . ' ' . $name;
			$linked = $url !== '' ? $this->formatTextLink($url, $bbName) : $bbName;

			$titles[] = '- ' . $plain;
			$bbTitles[] = '- ' . ($prefix === '' ? $linked : $prefix . ' ' . $linked)
				. (isset($overlapping[$index]) ? $overlapMark : '')
			;
		}

		return [$titles, $bbTitles];
	}

	/**
	 * Given the overlap icon, the node is rendered by the message reference: the lines follow the items
	 * of EVENTS_BLOCK_ITEMS_JSON, and a text line, having no icon, marks an overlap with the warning character.
	 */
	private function buildDisplayTitleLists(array $events, array $overlapping): array
	{
		$overlapMark = $this->getTextOverlapMark();
		if ($overlapMark !== '')
		{
			$titles = [];
			$bbTitles = [];
			foreach ($events as $index => $event)
			{
				$mark = isset($overlapping[$index]) ? $overlapMark : '';
				$titles[] = '- ' . $this->formatEventItemText($event, false) . $mark;
				$bbTitles[] = '- ' . $this->formatEventItemText($event, true, true) . $mark;
			}

			return [$titles, $bbTitles];
		}

		$titles = [];
		$bbTitles = [];

		foreach ($events as $index => $event)
		{
			$name = $this->normalizeDisplayText((string)($event['name'] ?? ''));
			$url = (string)($event['url'] ?? '');
			$prefix = $this->formatEventTimePrefix($event);
			$location = $this->normalizeDisplayText((string)($event['location'] ?? ''));

			$bbName = $this->neutralizeMessageText($name);

			$plain = $prefix === '' ? $name : $prefix . self::DISPLAY_TITLE_SEPARATOR . $name;
			$linked = $url !== '' ? $this->formatTextLink($url, $bbName) : $bbName;
			$linked = $prefix === '' ? $linked : $prefix . self::DISPLAY_TITLE_SEPARATOR . $linked;
			if ($location !== '')
			{
				$plain .= self::DISPLAY_METADATA_SEPARATOR . $location;
				$linked .= self::DISPLAY_METADATA_SEPARATOR . $this->neutralizeMessageText($location);
			}
			if (isset($overlapping[$index]))
			{
				$plain .= self::DISPLAY_METADATA_SEPARATOR . self::OVERLAP_WARNING_ICON;
				$linked .= self::DISPLAY_METADATA_SEPARATOR . self::OVERLAP_WARNING_ICON;
			}

			$titles[] = '- ' . $plain;
			$bbTitles[] = '- ' . $linked;
		}

		return [$titles, $bbTitles];
	}

	/**
	 * An overlap is marked by the icon property when OverlapIconType is set,
	 * by the warning character otherwise.
	 */
	private function buildBlockItems(array $events, array $overlapping): array
	{
		$color = $this->getDecorationToken(self::ITEM_COLOR);
		$iconType = $this->getDecorationToken(self::OVERLAP_ICON_TYPE);
		$iconColor = $this->getDecorationToken(self::OVERLAP_ICON_COLOR);

		$items = [];
		foreach ($events as $index => $event)
		{
			$isOverlapping = isset($overlapping[$index]);

			$text = $this->formatEventItemText($event, true);
			if ($isOverlapping && $iconType === '')
			{
				$text .= self::DISPLAY_METADATA_SEPARATOR . self::OVERLAP_WARNING_ICON;
			}

			$item = ['text' => $text];
			if ($color !== '')
			{
				$item['color'] = $color;
			}
			if ($isOverlapping && $iconType !== '')
			{
				$item['icon'] = ['type' => $iconType];
				if ($iconColor !== '')
				{
					$item['icon']['color'] = $iconColor;
				}
			}

			$items[] = $item;
		}

		return $items;
	}

	private function formatEventItemText(array $event, bool $withLink, bool $isMessageText = false): string
	{
		$name = $this->normalizeDisplayText((string)($event['name'] ?? ''));
		$url = (string)($event['url'] ?? '');
		$prefix = $this->formatEventTimePrefix($event);
		$location = $this->normalizeDisplayText((string)($event['location'] ?? ''));
		if ($isMessageText)
		{
			$name = $this->neutralizeMessageText($name);
			$location = $this->neutralizeMessageText($location);
		}

		$text = $name;
		if ($withLink && $url !== '')
		{
			$text = $isMessageText ? $this->formatTextLink($url, $name) : '[url=' . $url . ']' . $name . '[/url]';
		}
		$text = $prefix === '' ? $text : $prefix . ' ' . $text;
		if ($location !== '')
		{
			$text .= ' (' . $location . ')';
		}

		return $text;
	}

	/**
	 * The mark a text line of an overlap participant ends with once the blocks mark the overlap by the icon.
	 */
	private function getTextOverlapMark(): string
	{
		return $this->getDecorationToken(self::OVERLAP_ICON_TYPE) !== '' ? ' ' . self::OVERLAP_WARNING_ICON : '';
	}

	private function getDecorationToken(string $property): string
	{
		$value = $this->{$property};

		return trim((string)(is_array($value) ? reset($value) : $value));
	}

	private function replaceSquareBrackets(string $text): string
	{
		return str_replace(['[', ']'], ['(', ')'], $text);
	}

	/**
	 * The message text is converted from Markdown: brackets would open a Markdown link or a BB tag,
	 * angle brackets an autolink, even across lines, and invisible characters are dropped by the converter,
	 * joining what they separate.
	 */
	private function neutralizeMessageText(string $text): string
	{
		$text = preg_replace('/\p{Cf}/u', '', $text) ?? '';
		$text = $this->replaceSquareBrackets($text);

		return str_replace(['<', '>'], ["\u{2039}", "\u{203A}"], $text);
	}

	/**
	 * A title starting with "(" is kept apart from the tag by a plain space, so that "](" is not formed.
	 */
	private function formatTextLink(string $url, string $title): string
	{
		$guard = str_starts_with($title, '(') ? ' ' : '';

		return '[url=' . $url . ']' . $guard . $title . '[/url]';
	}

	private function normalizeDisplayText(string $text): string
	{
		$text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '';
		$text = $this->replaceSquareBrackets($text);

		return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 200);
	}

	private function findOverlappingEventIndexes(array $events): array
	{
		$timed = [];
		foreach ($events as $index => $event)
		{
			$dateFrom = (string)($event['dateFrom'] ?? '');
			$dateTo = (string)($event['dateTo'] ?? '');
			if (!str_contains($dateFrom, 'T') || !str_contains($dateTo, 'T'))
			{
				continue;
			}
			try
			{
				$from = (new \DateTimeImmutable($dateFrom))->getTimestamp();
				$to = (new \DateTimeImmutable($dateTo))->getTimestamp();
			}
			catch (\Throwable)
			{
				continue;
			}
			if ($from < $to)
			{
				$timed[] = ['index' => $index, 'from' => $from, 'to' => $to];
			}
		}
		usort($timed, static fn(array $left, array $right): int => [$left['from'], $left['to'], $left['index']] <=> [$right['from'], $right['to'], $right['index']]);

		$result = [];
		$maxEnd = null;
		$maxEndIndex = null;
		foreach ($timed as $event)
		{
			if ($maxEnd !== null && $event['from'] < $maxEnd)
			{
				$result[$event['index']] = true;
				$result[$maxEndIndex] = true;
			}
			if ($maxEnd === null || $event['to'] > $maxEnd)
			{
				$maxEnd = $event['to'];
				$maxEndIndex = $event['index'];
			}
		}

		return $result;
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
			self::EVENT_LIMIT => [
				'Name' => Loc::getMessage('BPSNMA_PD_EVENT_LIMIT'),
				'FieldName' => self::EVENT_LIMIT,
				'Type' => FieldType::INT,
				'Required' => false,
			],
		];
	}

	public static function getAiPassthroughSettingNames(array $documentType): array
	{
		return [self::CONFIRMED_CALL_PARTICIPATION];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return static::getPropertiesMap([]);
	}

	/**
	 * The inputs a template sets for the node and the form does not show are taken over from the node,
	 * so a save in the designer does not erase them. A new node has none to take over.
	 */
	protected static function extractPropertiesValues(PropertiesDialog $dialog, array $fieldsMap): Result
	{
		$result = parent::extractPropertiesValues($dialog, $fieldsMap);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$template = $dialog->getWorkflowTemplate();
		$node = is_array($template)
			? CBPWorkflowTemplateLoader::findActivityByName($template, $dialog->getActivityName())
			: null
		;
		$storedProperties = $node['Properties'] ?? null;
		if (!is_array($storedProperties))
		{
			return $result;
		}

		$properties = $result->getData();
		foreach (self::TEMPLATE_ONLY_INPUTS as $name)
		{
			if (!array_key_exists($name, $properties) && array_key_exists($name, $storedProperties))
			{
				$properties[$name] = $storedProperties[$name];
			}
		}
		$result->setData($properties);

		return $result;
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
		$events = $this->fetchRawEvents($ownerId, $calendarType, $fromTs, $toTs, $select, $viewerId);
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

	protected function fetchRawEvents(
		int $ownerId,
		string $calendarType,
		int $fromTs,
		int $toTs,
		array $select,
		int $viewerId,
		?int $sourceLimit = null,
		bool $halfOpenWindow = false,
		int $afterId = 0,
	): array
	{
		$dateFrom = Util::formatDateTimeTimestampUTC($fromTs);
		$dateTo = Util::formatDateTimeTimestampUTC(
			$halfOpenWindow ? $toTs - 1 : $toTs - \CCalendar::GetDayLen(),
		);

		$params = [
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
		];
		if ($sourceLimit !== null)
		{
			$params['limit'] = $sourceLimit;
			$params['arOrder'] = ['ID' => 'ASC'];
			$params['arFilter']['>ID'] = $afterId;
			$params['parseRecursion'] = false;
			$params['parseDescription'] = false;
		}

		return $this->getCalendarEvents($params);
	}

	protected function getCalendarEvents(array $params): array
	{
		return \CCalendarEvent::GetList($params);
	}

	private function fetchLimitedEvents(
		int $ownerId,
		string $calendarType,
		int $fromTs,
		int $toTs,
		array $select,
		int $viewerId,
		int $eventLimit,
	): array
	{
		$rawEvents = [];
		$rawCount = 0;
		$chunkFrom = $fromTs;
		$chunkCount = 0;
		$afterId = 0;
		while (
			$chunkFrom < $toTs
			&& $chunkCount < self::FETCH_INTERVAL_LIMIT
		)
		{
			$chunkTo = min($toTs, $chunkFrom + self::FETCH_INTERVAL_SECONDS);
			$pageSize = min(self::RAW_EVENT_PAGE_SIZE, self::RAW_EVENT_LIMIT - $rawCount + 1);
			$chunkEvents = $this->fetchRawEvents(
				$ownerId,
				$calendarType,
				$chunkFrom,
				$chunkTo,
				$select,
				$viewerId,
				$pageSize,
				true,
				$afterId,
			);
			$pageCount = count($chunkEvents);
			$remainingRawCount = self::RAW_EVENT_LIMIT - $rawCount;
			$rawCount += $pageCount;
			if ($rawCount > self::RAW_EVENT_LIMIT)
			{
				$chunkEvents = array_slice($chunkEvents, 0, $remainingRawCount);
			}
			foreach ($chunkEvents as $event)
			{
				$afterId = max($afterId, (int)$event['ID']);
				$instances = $this->expandEventOccurrences(
					$event,
					$chunkFrom,
					$chunkTo,
					$viewerId,
					self::EXPANDED_EVENT_LIMIT - count($rawEvents) + 1,
				);
				foreach ($instances as $instance)
				{
					$rawEvents[] = $instance;
				}
				if (count($rawEvents) > self::EXPANDED_EVENT_LIMIT)
				{
					break;
				}
			}
			if ($rawCount > self::RAW_EVENT_LIMIT || count($rawEvents) > self::EXPANDED_EVENT_LIMIT)
			{
				break;
			}
			if ($pageCount < $pageSize)
			{
				$chunkFrom = $chunkTo;
				$chunkCount++;
				$afterId = 0;
			}
		}

		if ($chunkFrom < $toTs)
		{
			$this->eventsTruncated = true;
			$rawEvents = array_slice($rawEvents, 0, self::EXPANDED_EVENT_LIMIT);
		}

		$events = $this->prepareRawEvents($rawEvents, $fromTs, $toTs);
		$events = $this->filterByCallParticipation($events, $viewerId, $fromTs, $toTs);
		if (count($events) > $eventLimit)
		{
			$this->eventsTruncated = true;
			$events = array_slice($events, 0, $eventLimit);
		}

		$this->meetingsTotalMinutes = $this->computeMeetingsTotalMinutes($events);

		return $this->formatEvents($events, $select, $calendarType, $viewerId);
	}

	private function expandEventOccurrences(
		array $event,
		int $fromTs,
		int $toTs,
		int $viewerId,
		int $resultLimit,
	): array
	{
		if (!\CCalendarEvent::CheckRecurcion($event))
		{
			return [$event];
		}

		$instances = [];
		\CTimeZone::Disable();
		try
		{
			\CCalendarEvent::ParseRecursion($instances, $event, [
				'userId' => $viewerId,
				'fromLimit' => Util::formatDateTimeTimestampUTC($fromTs),
				'toLimit' => Util::formatDateTimeTimestampUTC($toTs - 1),
				'loadLimit' => $resultLimit,
				'instanceCount' => false,
				'preciseLimits' => true,
				'includePreviousRecurrence' => true,
			]);
		}
		finally
		{
			\CTimeZone::Enable();
		}

		return array_slice($instances, 0, $resultLimit);
	}

	private function prepareRawEvents(array $events, int $fromTs, int $toTs): array
	{

		$uniqueEvents = [];
		foreach ($events as $event)
		{
			$interval = $this->getEventUtcInterval($event);
			if ($interval === null || $interval[0] >= $toTs || $interval[1] <= $fromTs)
			{
				continue;
			}

			$key = (int)($event['ID'] ?? 0) . '|' . $interval[0];
			if (!isset($uniqueEvents[$key]))
			{
				$uniqueEvents[$key] = $event;
			}
		}
		$events = array_values($uniqueEvents);
		$events = array_filter($events, fn(array $event) => !$this->isDeclinedMeeting($event));

		usort(
			$events,
			function(array $a, array $b): int
			{
				$aInterval = $this->getEventUtcInterval($a) ?? [0, 0];
				$bInterval = $this->getEventUtcInterval($b) ?? [0, 0];

				return [$aInterval[0], $aInterval[1], (int)($a['ID'] ?? 0)]
					<=> [$bInterval[0], $bInterval[1], (int)($b['ID'] ?? 0)];
			},
		);

		return $events;
	}

	private function getEventLimit(): ?int
	{
		$value = $this->{self::EVENT_LIMIT};
		if ($value === null || $value === '')
		{
			return null;
		}
		if (!is_numeric($value) || (int)$value < 1 || (int)$value > self::EVENT_LIMIT_MAX)
		{
			throw new \UnexpectedValueException('Calendar event limit is invalid.');
		}

		return (int)$value;
	}

	private function filterByCallParticipation(
		array $events,
		int $userId,
		int $fromTs,
		int $toTs,
	): array
	{
		if (!\CBPHelper::getBool($this->{self::CONFIRMED_CALL_PARTICIPATION}))
		{
			return $events;
		}

		$meetings = [];
		foreach ($events as $event)
		{
			$chatId = (int)($event['MEETING']['CHAT_ID'] ?? 0);
			$interval = $this->getEventUtcInterval($event);
			if ($chatId > 0 && $interval !== null && $interval[0] < $interval[1])
			{
				$meetingKey = (int)($event['ID'] ?? 0) . '|' . $interval[0];
				$meetings[$meetingKey] = [
					'chatId' => $chatId,
					'windowFrom' => (new \DateTimeImmutable())->setTimestamp($interval[0]),
					'windowTo' => (new \DateTimeImmutable())->setTimestamp($interval[1]),
				];
			}
		}

		$statuses = [];
		if ($meetings !== [] && Loader::includeModule('call') && class_exists(MeetingCallParticipationProvider::class))
		{
			try
			{
				$statuses = (new MeetingCallParticipationProvider())->getForMeetings(
					$meetings,
					$userId,
					(new \DateTimeImmutable())->setTimestamp($fromTs),
					(new \DateTimeImmutable())->setTimestamp($toTs),
				);
			}
			catch (\Throwable)
			{
				$statuses = [];
			}
		}

		$result = [];
		foreach ($events as $event)
		{
			$chatId = (int)($event['MEETING']['CHAT_ID'] ?? 0);
			$interval = $this->getEventUtcInterval($event);
			$meetingKey = (int)($event['ID'] ?? 0) . '|' . ($interval[0] ?? 0);
			$status = $chatId > 0
				? ($statuses[$meetingKey] ?? self::CALL_PARTICIPATION_UNKNOWN)
				: self::CALL_PARTICIPATION_UNKNOWN;
			if ($status === self::CALL_NOT_PARTICIPATED)
			{
				continue;
			}

			$event['_CONFIRMED_CALL_PARTICIPATION'] = $status === self::CALL_PARTICIPATED
				? true
				: null;
			$result[] = $event;
		}

		return $result;
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
			if (array_key_exists('_CONFIRMED_CALL_PARTICIPATION', $event))
			{
				$item['confirmedCallParticipation'] = $event['_CONFIRMED_CALL_PARTICIPATION'];
			}

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
		$isFullDayEvent = ($event['DT_SKIP_TIME'] ?? null) === 'Y';
		$dateFrom = $this->mapEventDateToObject(
			$event['DATE_FROM'] ?? null,
			$event['TZ_FROM'] ?? null,
			$isFullDayEvent,
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
			$isFullDayEvent,
		);
		if ($dateTo !== null && $isFullDayEvent)
		{
			$dateTo->add('+1 day');
		}
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
