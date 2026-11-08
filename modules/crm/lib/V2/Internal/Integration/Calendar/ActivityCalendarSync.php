<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Calendar;

use Bitrix\Calendar\Rooms;
use Bitrix\Crm\Activity\Provider\ToDo\BlocksManager;
use Bitrix\Crm\Activity\Provider\ToDo\ToDo;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\UserField\Types\ElementType;
use Bitrix\Crm\UserField\UserFieldManager;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use CCalendar;
use CCalendarEvent;
use CCrmActivityNotifyType;
use CCrmActivityPriority;
use DateTimeZone;

/**
 * The calendar side of an activity: the event of the calendar an activity keeps in step with (LBS-1 section 8,
 * checklist items G2.1-G2.7).
 *
 * THREE NARROW METHODS, ONE PER STEP. The add pipeline creates the event of a new activity, the update
 * pipeline keeps the event of a stored one in step with it, and both the delete and the branch of the update
 * that may not keep a completed activity in the calendar remove the event. The whole field mapping lives
 * here; the conditions the calendar block runs under at all - the predicates of the provider, the options of
 * the call, the completion logic (item G1) - stay in the steps, because they are conditions of the pipeline
 * rather than knowledge about the calendar. The id of the event is written back through {@see setEventId()},
 * which the step calls exactly where legacy does.
 *
 * WHAT CROSSES THE BOUNDARY. Types of CRM V2 and scalars, never a calendar event, a section or a user field
 * of the calendar. The answer of a synchronization is the id of the event or null - "nothing to write back",
 * which covers a calendar that is not installed, an activity without a responsible, a binding pointing at an
 * event that no longer exists, and a refusal of the calendar itself.
 *
 * THE CALENDAR IS AN OPTIONAL DEPENDENCY. A portal without the module keeps working: the synchronization is
 * skipped, no error is added to the result of the operation, and the caller cannot tell the difference from an
 * activity the calendar had nothing to do with. This is why the module is loaded with
 * {@see Loader::includeModule()} and not required.
 *
 * THE CALENDAR-ONLY INPUTS OF A CALL. A handful of values of the legacy input belong to the event and to
 * nothing else: the section, the attendees, the meeting flag, the user field of the bound objects, the
 * enriched description a provider computes and the blocks that description is enriched with. They are not
 * columns of an activity, which is why legacy deletes most of them from its field array as soon as it has read
 * them and the steps below it never see them (LBS-1 section 13 item 7, item G3). Here they are what they always
 * were - arguments of the call to the calendar - and the closed list of them is the INPUT_* constants below. An
 * unknown key is refused rather than ignored: a misspelled one would silently lose the value it carries.
 *
 * THE REVERSE DIRECTION IS SUPPRESSED, ONCE. Every write goes through
 * {@see CalendarReentrancyGuard::runIgnoringCalendarEvents()}, around the save and the user field of the
 * event alike, exactly the span legacy guards.
 *
 * @internal
 */
final class ActivityCalendarSync
{
	/** Section of the calendar the caller asks the event to be put into. */
	public const INPUT_SECTION_ID = 'sectionId';

	/** Access codes of the attendees of the event, in the form the calendar expects. */
	public const INPUT_ATTENDEES_CODES = 'attendeesCodes';

	/** Values of the user field of the event naming the CRM objects it belongs to. */
	public const INPUT_BOUND_ENTITY_VALUES = 'boundEntityValues';

	/** Whether the event is a meeting; a meeting also gets a host and the invitation settings. */
	public const INPUT_IS_MEETING = 'isMeeting';

	/**
	 * The description of the event, when it is not the description of the activity: the validation of a
	 * provider enriches the text with what the activity carries beside it (`ENRICHED_DESCRIPTION` of legacy).
	 */
	public const INPUT_DESCRIPTION = 'description';

	/**
	 * The description the event carried before, as the validation of a provider saw it
	 * (`PREV_ENRICHED_DESCRIPTION` of legacy). It only matters when the activity already has an event: a
	 * description that differs from it is appended to the text of the event instead of replacing it. An absent
	 * key is not the same as an empty one - for a to-do item it falls back to the text of the event, see
	 * {@see self::previousDescriptionOf()}.
	 */
	public const INPUT_PREVIOUS_DESCRIPTION = 'previousDescription';

	/**
	 * The blocks the description of the event is enriched with (`CALENDAR_ADDITIONAL_DESCRIPTION_DATA` of
	 * legacy): the links, the files and the meeting room the editor of the activity put beside its text, each
	 * of them a title and a list of items. They are appended to the description above, and where the activity
	 * has no text of its own they ARE the description - see {@see self::describedWithTheBlocks()}.
	 */
	public const INPUT_ADDITIONAL_DESCRIPTION_DATA = 'additionalDescriptionData';

	/** The meeting room a place names, zero when it names none - see {@see parsedLocationOf()}. */
	public const LOCATION_ROOM_ID = 'roomId';

	/** The place as a human reads it - see {@see parsedLocationOf()}. */
	public const LOCATION_TEXT = 'text';

	private const INPUTS = [
		self::INPUT_SECTION_ID,
		self::INPUT_ATTENDEES_CODES,
		self::INPUT_BOUND_ENTITY_VALUES,
		self::INPUT_IS_MEETING,
		self::INPUT_DESCRIPTION,
		self::INPUT_PREVIOUS_DESCRIPTION,
		self::INPUT_ADDITIONAL_DESCRIPTION_DATA,
	];

	private const MODULE_ID = 'calendar';

	private const SETTING_SECTION_ID = 'CALENDAR_SECTION_ID';

	private const SETTING_ADDRESS = 'ADDRESS_FORMATTED';

	private const SETTING_COLOR = 'COLOR';

	/**
	 * Creates the event of an activity that has just been stored.
	 *
	 * The reminders of the event come from the ping offsets of the activity, and only here: an event that
	 * already exists takes them from the notification fields instead (item G2.3).
	 *
	 * @param array<string, mixed> $calendarInput the calendar-only inputs of the call, INPUT_* keys.
	 * @return int|null the id of the event, or null when nothing was written.
	 * @throws ArgumentException on a key that is not one of the calendar-only inputs.
	 */
	public function createEvent(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
		array $calendarInput = [],
	): ?int
	{
		return $this->save($activity, $payload, $transit, $calendarInput, true);
	}

	/**
	 * Brings the event of a stored activity in step with it, and creates the event when the activity has
	 * none - the update of an activity is not necessarily the update of an event.
	 *
	 * @param array<string, mixed> $calendarInput the calendar-only inputs of the call, INPUT_* keys.
	 * @return int|null the id of the event, or null when nothing was written.
	 * @throws ArgumentException on a key that is not one of the calendar-only inputs.
	 */
	public function syncEvent(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
		array $calendarInput = [],
	): ?int
	{
		return $this->save($activity, $payload, $transit, $calendarInput, false);
	}

	/**
	 * Whether a synchronization would reach the calendar at all: an activity with a responsible, on a portal where
	 * the module is installed. It is the guard of {@see self::createEvent()} and {@see self::syncEvent()} asked on
	 * its own, and a step asks it because legacy reads the calendar-only inputs of its caller past the very same
	 * guards - returning before the first of them leaves those keys where they were (crm_activity.php:7322-7330).
	 */
	public function canSyncFor(Activity $activity): bool
	{
		return (int)($activity->getResponsibleId() ?? 0) > 0 && $this->isCalendarAvailable();
	}

	/**
	 * Removes the event of an activity, marking it deleted rather than erasing it.
	 *
	 * @return bool whether the removal was issued. The answer of the calendar itself is not inspected, which
	 *         is the answer legacy gives as well, and an event that is already gone counts as removed.
	 */
	public function deleteEvent(int $calendarEventId): bool
	{
		if ($calendarEventId <= 0 || !$this->isCalendarAvailable())
		{
			return false;
		}

		// 253583: hand the record over - the calendar reads its own with permission checks
		$event = $this->readEvent($calendarEventId);
		if ($event === null)
		{
			return true;
		}

		CalendarReentrancyGuard::runIgnoringCalendarEvents(
			static fn(): mixed => CCalendarEvent::Delete([
				'id' => $calendarEventId,
				'bMarkDeleted' => true,
				'Event' => $event,
			]),
		);

		return true;
	}

	/**
	 * Writes the id of the event into the activity, and nothing else.
	 *
	 * A WRITE PAST THE PIPELINE, ON PURPOSE. No event of the domain is published, no timestamp of the last
	 * change is bumped and no cache is dropped: the id of the event is a fact of the synchronization rather
	 * than a change of the activity, and legacy states it the same way (crm_activity.php:7269-7283, one of the
	 * silent writes of FIELD-AUDIT). Passing zero is how the binding is dropped when a completed activity may
	 * not stay in the calendar.
	 *
	 * @throws ArgumentException on an activity without an id, or when the column could not be written.
	 */
	public function setEventId(int $activityId, int $calendarEventId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('The id of the activity has to be positive', 'activityId');
		}

		$result = ActivityTable::update($activityId, ['CALENDAR_EVENT_ID' => $calendarEventId]);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not name the calendar event of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	/**
	 * The place named by a value in the encoding of the calendar: which meeting room it is, if it is one, and
	 * how it reads.
	 *
	 * ONLY THE CALENDAR KNOWS THAT ENCODING, so a provider that has to tell a room from a free-form address
	 * asks it here - the provider of to-do items does, when the calendar is the one writing and the place of the
	 * activity comes off the event. What crosses the boundary is two scalars and no type of the calendar.
	 *
	 * @param string $location the value of the LOCATION column, or the one an event carries.
	 * @return array{roomId: int, text: string}|null null when there is nothing to spell out: an empty value, or
	 *         a portal whose calendar is not installed. A place that is no meeting room answers a zero room.
	 */
	public function parsedLocationOf(string $location): ?array
	{
		if ($location === '' || !$this->isCalendarAvailable())
		{
			return null;
		}

		$parsed = (array)Rooms\Util::parseLocation($location);

		return [
			self::LOCATION_ROOM_ID => (int)($parsed['room_id'] ?? 0),
			self::LOCATION_TEXT => (string)($parsed['str'] ?? ''),
		];
	}

	/**
	 * The location of an activity as a human reads it: the value stored in the column names a meeting room or a
	 * free-form place in the encoding of the calendar, and only the calendar can spell it out.
	 *
	 * A portal without the module gets the stored value back unchanged, which is what legacy answers there as
	 * well (crm_activity.php:5513-5517, the only reader being the record of the history of an update).
	 */
	public function textOfLocation(string $location): string
	{
		if ($location === '' || !$this->isCalendarAvailable())
		{
			return $location;
		}

		return (string)CCalendar::GetTextLocation($location);
	}

	private function save(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
		array $calendarInput,
		bool $isCreation,
	): ?int
	{
		self::assertInput($calendarInput);

		if (!$this->canSyncFor($activity))
		{
			return null;
		}

		$responsibleId = (int)($activity->getResponsibleId() ?? 0);

		$calendarEventId = self::intOf($transit, 'CALENDAR_EVENT_ID');
		$presentEvent = null;
		if ($calendarEventId > 0)
		{
			$presentEvent = $this->readEvent($calendarEventId);
			if ($presentEvent === null)
			{
				// The bound event is gone (an orphan binding): there is nothing to bring in step, and
				// recreating it silently would put a second event into the calendar of the responsible.
				return null;
			}
		}

		$eventFields = $this->buildEventFields(
			$activity,
			$payload,
			$transit,
			$calendarInput,
			$responsibleId,
			$isCreation,
			$presentEvent,
		);
		$bindings = self::bindingsOf($activity, $transit);

		return CalendarReentrancyGuard::runIgnoringCalendarEvents(
			function () use ($eventFields, $responsibleId, $bindings): ?int {
				$saved = CCalendar::SaveEvent([
					'arFields' => $eventFields,
					'userId' => $responsibleId,
					'autoDetectSection' => true,
					'autoCreateSection' => true,
				]);

				$eventId = is_numeric($saved) ? (int)$saved : 0;
				if ($eventId <= 0)
				{
					return null;
				}

				$this->nameBoundEntities($eventId, $eventFields, $bindings);

				return $eventId;
			},
		);
	}

	/**
	 * The whole mapping of an activity onto an event of the calendar (items G2.1-G2.5). The order of the
	 * assignments is part of it: the address of the settings beats the location of the activity, the
	 * notification fields beat the reminders built from the ping offsets, and a completed activity ends up
	 * with no reminder at all.
	 *
	 * @param array<string, mixed> $calendarInput
	 * @param array<string, mixed>|null $presentEvent the event the activity is bound to, when it has one.
	 * @return array<string, mixed>
	 */
	private function buildEventFields(
		Activity $activity,
		ProviderPayload $payload,
		StorageFieldTransit $transit,
		array $calendarInput,
		int $responsibleId,
		bool $isCreation,
		?array $presentEvent,
	): array
	{
		$settings = self::settingsOf($payload);
		$description = self::describedWithTheBlocks(
			(string)($calendarInput[self::INPUT_DESCRIPTION] ?? $activity->getDescription() ?? ''),
			$calendarInput[self::INPUT_ADDITIONAL_DESCRIPTION_DATA] ?? null,
		);

		$fields = [
			'CAL_TYPE' => 'user',
			'OWNER_ID' => $responsibleId,
			'NAME' => (string)($activity->getSubject() ?? ''),
			'DATE_FROM' => self::momentOf($activity->getStartTime()),
			'DATE_TO' => self::momentOf($activity->getEndTime()),
			'IMPORTANCE' => CCrmActivityPriority::ToCalendarEventImportance(self::priorityOf($activity, $transit)),
			'DESCRIPTION' => $description,
			'SKIP_TIME' => false,
		];

		$sectionId = (int)($calendarInput[self::INPUT_SECTION_ID] ?? 0);
		if ($sectionId > 0)
		{
			// A section the author may not edit is dropped silently, and the calendar puts the event into the
			// CRM section of the responsible instead. The section of the settings is no fallback for it.
			if ($this->isSectionAvailable((int)($activity->getAuthorId() ?? 0), $sectionId))
			{
				$fields['SECTION_ID'] = $sectionId;
			}
		}
		elseif (!empty($settings[self::SETTING_SECTION_ID]))
		{
			$fields['SECTION_ID'] = (int)$settings[self::SETTING_SECTION_ID];
		}

		if (is_array($calendarInput[self::INPUT_ATTENDEES_CODES] ?? null))
		{
			$fields['ATTENDEES_CODES'] = $calendarInput[self::INPUT_ATTENDEES_CODES];
		}

		if (is_array($calendarInput[self::INPUT_BOUND_ENTITY_VALUES] ?? null))
		{
			$fields['UF_CRM_CAL_EVENT'] = $calendarInput[self::INPUT_BOUND_ENTITY_VALUES];
		}

		if (array_key_exists(self::INPUT_IS_MEETING, $calendarInput))
		{
			$fields = array_merge(
				$fields,
				self::meetingFields($calendarInput[self::INPUT_IS_MEETING], $responsibleId),
			);
		}

		if ($transit->has('LOCATION'))
		{
			$fields['LOCATION'] = (string)$transit->get('LOCATION');
		}

		if (isset($settings[self::SETTING_ADDRESS]))
		{
			$fields['LOCATION'] = $settings[self::SETTING_ADDRESS];
		}

		$color = $this->colorOf($settings);
		if ($color !== null)
		{
			$fields['COLOR'] = $color;
		}

		$offsets = $activity->getPingOffsets() ?? [];
		if ($isCreation && $offsets !== [])
		{
			$fields['REMIND'] = self::remindersOfOffsets($offsets);
		}

		// The converted moments replace the ones of the mapping above, they do not fall in behind them.
		$fields = array_merge($fields, $this->timeFields($activity, $responsibleId));
		$fields['SECTIONS'] = [CCalendar::GetCrmSection($responsibleId, true)];

		if ($presentEvent !== null)
		{
			$fields['ID'] = (int)$presentEvent['ID'];

			if (!empty($presentEvent['RRULE']))
			{
				$fields['RRULE'] = CCalendarEvent::ParseRRULE($presentEvent['RRULE']);
			}

			$fields['DESCRIPTION'] = self::descriptionAfter(
				(string)($presentEvent['DESCRIPTION'] ?? ''),
				$description,
				self::previousDescriptionOf($activity, $calendarInput, $presentEvent),
			);
		}

		$notifyType = self::intOf($transit, 'NOTIFY_TYPE');
		if ($notifyType !== CCrmActivityNotifyType::None)
		{
			$fields['REMIND'] = [
				[
					'type' => CCrmActivityNotifyType::ToCalendarEventRemind($notifyType),
					'count' => $transit->has('NOTIFY_VALUE') ? self::intOf($transit, 'NOTIFY_VALUE') : 15,
				],
			];
		}

		if ($activity->isCompleted() === true)
		{
			$fields['REMIND'] = [];
		}

		return $fields;
	}

	/**
	 * Names the CRM objects of the activity in the user field of the event. The values the caller handed over
	 * are kept and the bindings are appended to them, for the owner types the user field is enabled for.
	 *
	 * @param array<string, mixed> $eventFields
	 * @param ActivityBinding[] $bindings
	 */
	private function nameBoundEntities(int $calendarEventId, array $eventFields, array $bindings): void
	{
		if ($bindings === [])
		{
			return;
		}

		$values = $eventFields['UF_CRM_CAL_EVENT'] ?? [];
		foreach ($bindings as $binding)
		{
			$ownerType = $binding->getOwnerType();
			if (!UserFieldManager::isEnabledInCalendarUserField($ownerType->getName()))
			{
				continue;
			}

			$values[] = ElementType::getValueByIdentifier(
				new ItemIdentifier($ownerType->getId(), $binding->getOwnerId()),
			);
		}

		CCalendarEvent::UpdateUserFields($calendarEventId, ['UF_CRM_CAL_EVENT' => $values]);
	}

	/**
	 * The start and the end of the activity in the time of the owner of the calendar (item G2.4): the model
	 * carries the moments themselves, and the event carries the wall clock of the responsible user beside the
	 * name of their zone. A user without a zone leaves both out, exactly as legacy does, and the event keeps
	 * the values of the mapping above.
	 *
	 * @return array<string, string>
	 */
	private function timeFields(Activity $activity, int $responsibleId): array
	{
		$zoneName = CCalendar::GetUserTimezoneName($responsibleId, true);
		if (!is_string($zoneName) || $zoneName === '')
		{
			return [];
		}

		$zone = new DateTimeZone($zoneName);
		$fields = [];

		$startTime = $activity->getStartTime();
		if ($startTime !== null)
		{
			$fields['DATE_FROM'] = self::momentIn($startTime, $zone);
			$fields['TZ_FROM'] = $zoneName;
		}

		$endTime = $activity->getEndTime();
		if ($endTime !== null)
		{
			$fields['DATE_TO'] = self::momentIn($endTime, $zone);
			$fields['TZ_TO'] = $zoneName;
		}

		return $fields;
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function colorOf(array $settings): ?string
	{
		if (!isset($settings[self::SETTING_COLOR]))
		{
			return null;
		}

		$colorSettings = (new ColorSettingsProvider())->getByColorId((string)$settings[self::SETTING_COLOR]);

		return isset($colorSettings['color']) ? (string)$colorSettings['color'] : null;
	}

	private function isSectionAvailable(int $userId, int $sectionId): bool
	{
		foreach ((array)CCalendar::getSectionListAvailableForUser($userId) as $section)
		{
			if ((int)($section['ID'] ?? 0) === $sectionId && ($section['PERM']['edit'] ?? null) === true)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The event the activity is bound to, read WITHOUT the permissions of the acting user
	 * (crm_activity.php:7612-7615).
	 *
	 * IT IS A LOOKUP OF THE PREVIOUS STATE, NOT AN AUTHORIZATION POINT. The write below is authorized by the
	 * calendar as the person responsible, while a read made as the acting user answers nothing for an event that
	 * user may not see - and the only thing this layer does with "nothing" is treat the binding as an orphan and
	 * drop the whole synchronization. A live event would be silently left behind.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readEvent(int $calendarEventId): ?array
	{
		$event = CCalendarEvent::GetById($calendarEventId, false);

		return is_array($event) ? $event : null;
	}

	private function isCalendarAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	/**
	 * The description of the event with the blocks of the activity appended to it: the links, the files and the
	 * meeting room the editor put beside the text, each under its title.
	 *
	 * THE THREE ANSWERS ARE THE ONES LEGACY GIVES. Where there are no blocks the text is handed back untouched;
	 * where there is no text the blocks ARE the description, the title of a description with nothing under it
	 * saying nothing; and where there are both, the blocks follow the text on a line of their own. Which is why
	 * this is folded in here rather than in the caller: the whole text is what the event is described with, what
	 * a repeated synchronization compares against ({@see self::descriptionAfter()}) and what the previous
	 * description of the provider is measured against.
	 *
	 * @param mixed $blocks the value the caller named, which is a map of blocks or nothing at all.
	 */
	private static function describedWithTheBlocks(string $description, mixed $blocks): string
	{
		if (!is_array($blocks))
		{
			return $description;
		}

		$blocksText = self::blocksText($blocks);
		if ($blocksText === '')
		{
			return $description;
		}

		return $description === '' ? $blocksText : $description . PHP_EOL . $blocksText;
	}

	/**
	 * The blocks as one text: every block is its title followed by its items, and a block with no item is left
	 * out entirely. A single item stands on the line of the title and several are listed under it - the shape
	 * legacy composes (`ToDo::implodeAdditionalDescriptionData()`), reproduced with its ordering
	 * (`ToDo::sortAdditionalDescriptionData()`) rather than tidied up: the order the blocks are shown in is
	 * observable in every event of every portal.
	 *
	 * @param array<string, mixed> $blocks
	 */
	private static function blocksText(array $blocks): string
	{
		self::sortBlocks($blocks);

		$lines = [];
		foreach ($blocks as $block)
		{
			$items = is_array($block) ? ($block['ITEMS'] ?? []) : [];
			if (!is_array($items) || empty($items))
			{
				continue;
			}

			$separator = (count($items) > 1 ? PHP_EOL : ' ');
			$lines[] = implode($separator, [$block['TITLE'] ?? '', implode(', ' . PHP_EOL, $items)]);
		}

		return implode(PHP_EOL, $lines);
	}

	/**
	 * The order of the blocks, by the comparison legacy makes. It is copied as it stands, quirk included: the
	 * declared order of the blocks is what the comparison reads, and what it really answers for a block absent
	 * from that declaration is not something a rewrite may decide differently. The one difference is the cast of
	 * the answer to an integer, which the comparison of legacy leaves to PHP - the ordering is the same and the
	 * deprecation of a boolean answer is not carried over.
	 *
	 * @param array<string, mixed> $blocks
	 */
	private static function sortBlocks(array &$blocks): void
	{
		$declared = [];
		foreach (BlocksManager::getBlocks() as $block)
		{
			$declared[$block['id']] = $block['sort'];
		}

		uksort(
			$blocks,
			static fn(string $a, string $b): int =>
				(int)($declared[strtolower($a)] ?? null < $declared[strtolower($b)] ?? null)
		);
	}

	/**
	 * The description the event carried before, as the comparison below needs it: the one the caller named, and
	 * for a to-do item the text of the event itself where the caller named none (crm_activity.php:7630-7633).
	 *
	 * THE FALLBACK IS A RULE OF THE TO-DO PROVIDER AND OF NO OTHER. Its editor reaches this layer on paths that
	 * carry no previous description at all, and without the fallback a changed text replaces the description of
	 * the event instead of being appended to it under the subtitle. A caller that named an empty text named one
	 * all the same: only an absent value falls back.
	 *
	 * @param array<string, mixed> $calendarInput
	 * @param array<string, mixed> $presentEvent
	 */
	private static function previousDescriptionOf(
		Activity $activity,
		array $calendarInput,
		array $presentEvent,
	): string
	{
		$previousDescription = $calendarInput[self::INPUT_PREVIOUS_DESCRIPTION] ?? null;
		if ($previousDescription === null && (string)$activity->getProviderId() === ToDo::getId())
		{
			$previousDescription = $presentEvent['DESCRIPTION'] ?? null;
		}

		return (string)$previousDescription;
	}

	/**
	 * The description of an event that already exists is not replaced by a changed one: the new text is
	 * appended to the old one under a dated subtitle (item G2.5). The description the provider saw before is
	 * what tells a change from a repeated synchronization; without it the text of the event is replaced.
	 */
	private static function descriptionAfter(
		string $presentDescription,
		string $description,
		string $previousDescription,
	): string
	{
		if ($previousDescription === '' || trim($description) === trim($previousDescription))
		{
			return $description;
		}

		return $presentDescription . PHP_EOL . PHP_EOL . self::subtitle() . PHP_EOL . $description;
	}

	/**
	 * The phrase of the subtitle belongs to the legacy pipelines: the calendar block moved here, the phrase
	 * stayed where its translations are, and a second copy of the wording would fork the two. The moment is
	 * written in the format of the culture - legacy asks for a short one and drops the answer
	 * (crm_activity.php:7154-7159), so the format of the culture is what the portal shows today.
	 */
	private static function subtitle(): string
	{
		$legacyPipelines = dirname(__DIR__, 5) . '/classes/general/crm_activity.php';
		Loc::loadLanguageFile($legacyPipelines);

		return (string)Loc::getMessage('CRM_ACTIVITY_CALENDAR_SUBTITLE', ['#DATE#' => (string)(new DateTime())]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function meetingFields(mixed $isMeeting, int $responsibleId): array
	{
		$hostId = Container::getInstance()->getContext()->getUserId();
		$fields = [
			'IS_MEETING' => $isMeeting,
			'MEETING_HOST' => $hostId > 0 ? $hostId : $responsibleId,
		];

		if ($isMeeting === true)
		{
			$fields['MEETING'] = [
				'NOTIFY' => true,
				'REINVITE' => false,
				'ALLOW_INVITE' => false,
				'MEETING_CREATOR' => $responsibleId,
				'HIDE_GUESTS' => true,
			];
		}

		return $fields;
	}

	/**
	 * @param int[] $offsets
	 * @return array<int, array{type: string, count: int}>
	 */
	private static function remindersOfOffsets(array $offsets): array
	{
		$reminders = [];
		foreach ($offsets as $offset)
		{
			if ($offset < 0)
			{
				continue;
			}

			$reminders[] = ['type' => 'min', 'count' => $offset];
		}

		return $reminders;
	}

	/**
	 * The bindings the user field of the event is built from. An activity that carries none falls back to its
	 * owner columns, which is the binding of the owner legacy rebuilds there.
	 *
	 * @return ActivityBinding[]
	 */
	private static function bindingsOf(Activity $activity, StorageFieldTransit $transit): array
	{
		$bindings = $activity->getBindings() ?? [];
		if ($bindings !== [])
		{
			return $bindings;
		}

		$ownerId = self::intOf($transit, 'OWNER_ID');
		$ownerTypeId = self::intOf($transit, 'OWNER_TYPE_ID');
		if ($ownerId <= 0 || $ownerTypeId <= 0)
		{
			return [];
		}

		return [new ActivityBinding(OwnerType::fromId($ownerTypeId), $ownerId)];
	}

	/**
	 * The grade of the priority the importance of the event is built from. The three grades of the column are
	 * the richer form; the flag of the model only tells the highest one from the rest.
	 */
	private static function priorityOf(Activity $activity, StorageFieldTransit $transit): int
	{
		if ($transit->has('PRIORITY'))
		{
			return self::intOf($transit, 'PRIORITY');
		}

		return $activity->isImportant() === true ? CCrmActivityPriority::High : CCrmActivityPriority::Low;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function settingsOf(ProviderPayload $payload): array
	{
		if (!$payload->has(ProviderPayload::settings))
		{
			return [];
		}

		$settings = $payload->get(ProviderPayload::settings);

		return is_array($settings) ? $settings : [];
	}

	private static function momentOf(?DateTime $moment): string
	{
		return $moment === null ? '' : (string)$moment;
	}

	private static function momentIn(DateTime $moment, DateTimeZone $zone): string
	{
		$converted = clone $moment;
		$converted->setTimeZone($zone);

		return $converted->format(DateTime::getFormat());
	}

	private static function intOf(StorageFieldTransit $transit, string $column): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : 0;
	}

	/**
	 * @param array<string, mixed> $calendarInput
	 * @throws ArgumentException
	 */
	private static function assertInput(array $calendarInput): void
	{
		foreach (array_keys($calendarInput) as $key)
		{
			if (!in_array($key, self::INPUTS, true))
			{
				throw new ArgumentException("'{$key}' is not a calendar input of an activity", 'calendarInput');
			}
		}
	}
}
