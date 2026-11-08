<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Provider\Native;

use Bitrix\Crm\Activity\TodoPingSettingsProvider;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Badge\SourceIdentifier;
use Bitrix\Crm\Badge\Type\TodoStatus;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\Calendar;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Integration\Calendar\ActivityCalendarSync;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ActivityProviderInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\BadgeCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CalendarCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\CompletionCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\PingCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Capability\TimelineCapabilityInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\Timeline\TimelineItemRequest;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ValidationContext;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

/**
 * The provider of a to-do item, written against the contract of a V2 provider instead of being wrapped.
 *
 * THE PILOT OF THE MIGRATION. Every other provider is still a legacy class seen through the wrapper; this one
 * states its rules itself, and the registry hands it out as it is. To-do items were chosen because they use
 * more of the contract than anybody else - the calendar with a condition of its own, the reminders, the badge
 * of an overlapping event, the postponing, a card in the timeline - so a contract that carries them carries the
 * rest.
 *
 * THE LEGACY CLASS GOES ON EXISTING AND IS NOT CALLED FROM HERE. `Activity\Provider\ToDo\ToDo` remains the
 * provider of the surfaces that have not been migrated - the planner, the forms, the factory of legacy
 * instances, and the whole write path while the delegation flag is off - and the descriptor of this provider
 * keeps answering that class name for them. On the V2 path the rules live here and nowhere else, so the two
 * coexist until the legacy readers are migrated. The one exception is deliberate and is no rule at all: the UI
 * name and the title of the description of a calendar event are PHRASES of the legacy file, and localization
 * resolves a phrase by the file that asks for it - so the phrases are loaded from there rather than copied,
 * because a second copy would have to be translated twice and would drift.
 *
 * WHAT IT READS AND WHERE FROM. A to-do reads three things no typed model of an activity holds: the identifier
 * of the calendar event it is bound to, the settings of its provider and the owner of the activity. All three
 * are columns or serialized fields, so they arrive through the payload and the storage columns of the operation
 * - of the INPUT of it, which is what the contracts of the calendar, of the reminders and of the badges hand
 * over.
 *
 * THE CALENDAR IS TALKED TO THROUGH THE INTEGRATION OF THE MODULE. Reading an event and the sections a person
 * may write into goes through `Crm\Integration\Calendar`, which is what legacy asks and belongs to this module;
 * the encoding of a meeting room is spelled out by the calendar layer of V2
 * ({@see ActivityCalendarSync::parsedLocationOf()}), because that encoding belongs to the module of the
 * calendar and no code outside an integration layer may ask it.
 *
 * @internal
 */
final class ToDoProvider implements
	ActivityProviderInterface,
	BadgeCapabilityInterface,
	CalendarCapabilityInterface,
	CompletionCapabilityInterface,
	PingCapabilityInterface,
	TimelineCapabilityInterface
{
	/**
	 * The value of the PROVIDER_ID column of a to-do item. Stated literally rather than read off the legacy
	 * class: the value is stored in rows written years ago and may never change, and a test compares the two
	 * so that they cannot part company.
	 */
	public const ID = 'CRM_TODO';

	/** The one kind of activity a to-do has. */
	public const DEFAULT_TYPE_ID = 'TODO';

	private const COLUMN_CALENDAR_EVENT_ID = 'CALENDAR_EVENT_ID';
	private const COLUMN_DEADLINE = 'DEADLINE';
	private const COLUMN_LOCATION = 'LOCATION';
	private const COLUMN_OWNER_ID = 'OWNER_ID';
	private const COLUMN_OWNER_TYPE_ID = 'OWNER_TYPE_ID';

	private const SETTING_ADDRESS = 'ADDRESS_FORMATTED';
	private const SETTING_COLOR = 'COLOR';
	private const SETTING_LOCATION = 'LOCATION';
	private const SETTING_SECTION_ID = 'CALENDAR_SECTION_ID';
	private const SETTING_TAGS = 'TAGS';
	private const SETTING_USERS = 'USERS';

	private const TAG_OVERLAP_EVENT = 'OVERLAP_EVENT';

	/**
	 * The keys of the enriched description of a calendar event: values of the run that are no columns of an
	 * activity, answered in the data of a validation and carried by the pipeline to the calendar step.
	 */
	private const KEY_ENRICHED_DESCRIPTION = 'ENRICHED_DESCRIPTION';
	private const KEY_PREVIOUS_ENRICHED_DESCRIPTION = 'PREV_ENRICHED_DESCRIPTION';

	private readonly ActivityCalendarSync $calendar;

	public function __construct(?ActivityCalendarSync $calendar = null)
	{
		$this->calendar = $calendar ?? new ActivityCalendarSync();
	}

	//region the mandatory core

	public function getId(): string
	{
		return self::ID;
	}

	public function getName(): string
	{
		return self::phraseOf('CRM_ACTIVITY_TODO_NAME', 'lib/activity/provider/todo/todo.php');
	}

	/**
	 * A to-do applies to every portal, and its feature is never switched off.
	 */
	public function isAvailable(): bool
	{
		return true;
	}

	public function isActive(): bool
	{
		return true;
	}

	public function getTypes(): array
	{
		return [
			[
				'name' => $this->getName(),
				'providerTypeId' => self::DEFAULT_TYPE_ID,
				'directions' => [],
			],
		];
	}

	public function getDefaultTypeId(): ?string
	{
		return self::DEFAULT_TYPE_ID;
	}

	/**
	 * THE DEADLINE OF A TO-DO IS ITS START TIME, and this is the only place it is derived: the model treats the
	 * column as read-only precisely because a provider owns it.
	 *
	 * WHAT A CALENDAR-INITIATED OPERATION DOES NOT OVERWRITE. When the calendar is the one writing, the values
	 * a person edits in CRM are left as the calendar has them: the description of an update comes back from the
	 * state before the write, a new activity gets the default colour, and the attendees and the place are taken
	 * off the event itself. Without that, saving an event in the calendar would silently rewrite the text of
	 * the activity with the text the event was built from.
	 *
	 * THE ENRICHED DESCRIPTION IS ANSWERED, NOT WRITTEN. The text a calendar event is described with is no
	 * column of an activity, so it travels in the data of the result to the step synchronizing the event, which
	 * is its only reader.
	 */
	public function validate(Activity $activity, ProviderPayload $payload, ValidationContext $context): Result
	{
		$transit = $context->getTransit();
		$calendarEventId = self::calendarEventIdOf($transit);

		$result = (new Result())->setData($this->enrichedDescriptionOf($activity, $calendarEventId));

		$startTime = $activity->getStartTime();
		if ($startTime !== null)
		{
			$transit->set(self::COLUMN_DEADLINE, $startTime);
		}

		if (!$context->isInitiatedByCalendar())
		{
			return $result;
		}

		if ($context->isUpdate())
		{
			$activity->setDescription(trim((string)$context->getPreviousActivity()?->getDescription()));
		}

		$settings = self::settingsOf($payload);
		$given = $settings;

		if ($context->isAdd())
		{
			$settings[self::SETTING_COLOR] = ColorSettingsProvider::getDefaultColorId();
		}

		$this->applyEventOfTheCalendar($settings, $transit, $calendarEventId);

		if ($settings !== $given)
		{
			$payload->set(ProviderPayload::settings, $settings);
		}

		return $result;
	}

	/**
	 * The text the calendar event of this activity is described with, and the text the event carries today.
	 *
	 * The second one is answered only for an event that really exists: its reader appends the description of
	 * the activity to the one the event already has, and an event nobody found has nothing to append to.
	 *
	 * @return array<string, mixed>
	 */
	private function enrichedDescriptionOf(Activity $activity, int $calendarEventId): array
	{
		$data = [self::KEY_ENRICHED_DESCRIPTION => self::preparedDescriptionOf($activity)];

		$event = $calendarEventId > 0 ? Calendar::getEvent($calendarEventId) : null;
		if (is_array($event))
		{
			$data[self::KEY_PREVIOUS_ENRICHED_DESCRIPTION] = $event['DESCRIPTION'] ?? null;
		}

		return $data;
	}

	/**
	 * The description of the activity under the title a calendar event shows it with, or nothing at all when
	 * the activity has no description: an event described with the title alone would say nothing.
	 */
	private static function preparedDescriptionOf(Activity $activity): string
	{
		$description = TextHelper::removeParagraphs((string)$activity->getDescription());

		if (empty($description))
		{
			return '';
		}

		$title = self::phraseOf('CRM_ACTIVITY_CALENDAR_DESCRIPTION_TITLE', 'classes/general/crm_activity.php');

		return $title . ' ' . $description;
	}

	/**
	 * The attendees and the place of the event the activity is bound to, put where a to-do keeps them.
	 *
	 * A PLACE THE CALENDAR CANNOT SPELL OUT LEAVES BOTH SETTINGS BEHIND. An event without a place, and a portal
	 * whose calendar is not installed, take the settings of the place out instead of writing them: whatever the
	 * activity used to say about a room would otherwise outlive the event that named it.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function applyEventOfTheCalendar(
		array &$settings,
		StorageFieldTransit $transit,
		int $calendarEventId,
	): void
	{
		if ($calendarEventId <= 0)
		{
			return;
		}

		$event = Calendar::getEvent($calendarEventId);
		if (!is_array($event))
		{
			return;
		}

		$settings[self::SETTING_USERS] = array_map(
			static fn(mixed $attendee): mixed => is_array($attendee) ? ($attendee['id'] ?? null) : null,
			(array)($event['attendeesEntityList'] ?? []),
		);

		$location = empty($event[self::COLUMN_LOCATION])
			? null
			: $this->calendar->parsedLocationOf((string)$event[self::COLUMN_LOCATION])
		;

		if ($location === null)
		{
			unset($settings[self::SETTING_LOCATION], $settings[self::SETTING_ADDRESS]);

			return;
		}

		if ($location[ActivityCalendarSync::LOCATION_ROOM_ID] > 0)
		{
			$transit->set(self::COLUMN_LOCATION, $location[ActivityCalendarSync::LOCATION_TEXT]);
			$settings[self::SETTING_LOCATION] = $location[ActivityCalendarSync::LOCATION_TEXT];

			return;
		}

		$transit->set(self::COLUMN_LOCATION, '');
		$settings[self::SETTING_ADDRESS] = $location[ActivityCalendarSync::LOCATION_TEXT];
	}

	//endregion

	//region the calendar

	/**
	 * A to-do is shown in the calendar, whatever kind of to-do it is.
	 */
	public function canUseCalendarEvents(?string $providerTypeId = null): bool
	{
		return true;
	}

	public function canAddCalendarEvents(?string $providerTypeId = null): bool
	{
		return true;
	}

	/**
	 * A completed to-do leaves the calendar, and a reassigned one does not travel with its event.
	 */
	public function canKeepCompletedInCalendar(?string $providerTypeId = null): bool
	{
		return false;
	}

	public function canKeepReassignedInCalendar(?string $providerTypeId = null): bool
	{
		return false;
	}

	/**
	 * Whether this operation leaves the calendar alone, decided by the INPUT of it - which is why the columns
	 * of the operation are here and not the state of the row.
	 *
	 * AN EVENT THAT IS ALREADY BOUND IS ALWAYS SYNCHRONIZED. The dates of it were authorized against the event
	 * itself before the operation began, so no question about the section may stop them from arriving.
	 *
	 * A NEW EVENT NEEDS A SECTION SOMEBODY MAY WRITE INTO, and an operation somebody started by hand is where
	 * that is asked: an automated one writes into the section of the CRM whatever the acting person may reach.
	 * Then the answer is the one the caller gave, and a caller that gave none does not want an event - the
	 * default of the option is the reason a to-do created from the timeline gets no event of its own.
	 */
	public function skipCalendarSync(Activity $activity, ActivityOperationContext $context): bool
	{
		$transit = $context->getInputTransit();
		if (self::hasBoundCalendarEvent($transit))
		{
			return false;
		}

		if ($context->getScope() !== Scope::Automation)
		{
			$sectionId = (int)(self::settingsOf($context->getInputPayload())[self::SETTING_SECTION_ID] ?? 0);
			if ($sectionId <= 0)
			{
				return true;
			}

			if (!self::isSectionAvailable($sectionId))
			{
				return true;
			}
		}

		return $context->shouldSkipCurrentCalendarEvent();
	}

	/**
	 * Whether the acting person may write into the section of the calendar the operation named.
	 */
	private static function isSectionAvailable(int $sectionId): bool
	{
		$userId = Container::getInstance()->getContext()->getUserId();
		foreach (Calendar::getSectionListAvailableForUser($userId) as $section)
		{
			if ((int)($section['ID'] ?? 0) === $sectionId)
			{
				return true;
			}
		}

		return false;
	}

	//endregion

	//region completing and postponing

	public function isCompletable(): bool
	{
		return true;
	}

	/**
	 * An activity that is not completed is one somebody may still be waiting for.
	 */
	public function checkForWaitingCompletion(Activity $activity): bool
	{
		return $activity->isCompleted() !== true;
	}

	public function canCompleteOnView(): bool
	{
		return false;
	}

	/**
	 * Moves the to-do by the given number of seconds: both ends of its interval travel, and the deadline
	 * follows them because the check of the fields derives it from the start time again.
	 *
	 * NOTHING IS RECORDED IN THE SET OF CHANGES, which is the shape of this capability: a provider shifts its
	 * own fields and the write path answers what changed.
	 */
	public function tryPostpone(int $offsetSeconds, Activity $activity, ActivityChangeSet $changes): Result
	{
		$startTime = $activity->getStartTime();
		if ($startTime !== null)
		{
			$activity->setStartTime(self::shiftedBy($startTime, $offsetSeconds));
		}

		$endTime = $activity->getEndTime();
		if ($endTime !== null)
		{
			$activity->setEndTime(self::shiftedBy($endTime, $offsetSeconds));
		}

		return new Result();
	}

	private static function shiftedBy(DateTime $moment, int $offsetSeconds): DateTime
	{
		return DateTime::createFromTimestamp($moment->getTimestamp() + $offsetSeconds);
	}

	//endregion

	//region reminders

	/**
	 * The reminders a to-do of this kind of owner gets when the caller named none: the setting of the person
	 * acting, by the type of the owner and its funnel.
	 */
	public function getDefaultPingOffsets(int $entityTypeId, ?int $categoryId): array
	{
		return array_map(
			'intval',
			(new TodoPingSettingsProvider($entityTypeId, (int)($categoryId ?? 0)))->getCurrentOffsets(),
		);
	}

	/**
	 * A to-do bound to a calendar event is reminded of by the calendar, so its own queue of reminders is not
	 * kept - which on an update means the entry is taken out of it.
	 */
	public function needSynchronizePingQueue(Activity $activity, ActivityOperationContext $context): bool
	{
		return !self::hasBoundCalendarEvent($context->getInputTransit());
	}

	//endregion

	//region badges

	/**
	 * Puts the badge of an overlapping event on the owner of the activity, or takes it off again.
	 *
	 * THE SET OF OWNERS IS IGNORED, and that is the rule of this provider rather than an omission: the badge of
	 * a to-do belongs to the owner the activity itself names, and the caller passing a set of bindings does not
	 * change which one that is. Whether the events really overlap is decided before the operation, by whoever
	 * puts the tag into the settings.
	 *
	 * @param array $bindings ignored, see above.
	 */
	public function syncBadges(
		int $activityId,
		Activity $activity,
		array $bindings,
		ActivityOperationContext $context,
	): void
	{
		$transit = $context->getInputTransit();
		$ownerTypeId = self::intOf($transit, self::COLUMN_OWNER_TYPE_ID);
		$ownerId = self::intOf($transit, self::COLUMN_OWNER_ID);
		if ($ownerTypeId <= 0 || $ownerId <= 0)
		{
			return;
		}

		$badge = Container::getInstance()->getBadge(
			TodoStatus::TODO_STATUS_TYPE,
			TodoStatus::OVERLAP_EVENT_VALUE,
		);
		$owner = new ItemIdentifier($ownerTypeId, $ownerId);
		$source = new SourceIdentifier(
			SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		$settings = self::settingsOf($context->getInputPayload());
		if (isset($settings[self::SETTING_TAGS][self::TAG_OVERLAP_EVENT]))
		{
			$badge->bind($owner, $source);

			return;
		}

		if ($badge->isBound($owner, $source))
		{
			$badge->unbind($owner, $source);
		}
	}

	//endregion

	//region the timeline

	public function isTimelineSupported(): bool
	{
		return true;
	}

	public function isSupportedByTimelineController(): bool
	{
		return true;
	}

	/**
	 * A to-do has one card and needs nothing of the activity to choose it.
	 */
	public function createTimelineItem(TimelineItemRequest $request): ?object
	{
		return new Item\Activity\ToDo($request->getContext(), $request->getModel());
	}

	//endregion

	//region what a to-do reads out of the operation

	private static function calendarEventIdOf(StorageFieldTransit $transit): int
	{
		return self::intOf($transit, self::COLUMN_CALENDAR_EVENT_ID);
	}

	/**
	 * Whether the operation names a calendar event the activity is bound to. Asked of the value the way legacy
	 * asks it: a zero and an empty value are no event.
	 */
	private static function hasBoundCalendarEvent(StorageFieldTransit $transit): bool
	{
		return $transit->has(self::COLUMN_CALENDAR_EVENT_ID)
			&& !empty($transit->get(self::COLUMN_CALENDAR_EVENT_ID))
		;
	}

	private static function intOf(StorageFieldTransit $transit, string $column): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : 0;
	}

	/**
	 * The settings of the provider as the operation carries them, and an empty set where it carries none.
	 *
	 * @return array<string, mixed>
	 */
	private static function settingsOf(ProviderPayload $payload): array
	{
		$settings = $payload->has(ProviderPayload::settings)
			? $payload->get(ProviderPayload::settings)
			: null
		;

		return is_array($settings) ? $settings : [];
	}

	/**
	 * A phrase of a legacy file of the module. Localization resolves a phrase by the file that asks for it, so
	 * the file holding the phrase is named here instead of a second copy of it being made: a copy would have to
	 * be translated twice and the two would drift.
	 *
	 * @param string $legacyFile the path of the file the phrase belongs to, relative to the module.
	 */
	private static function phraseOf(string $code, string $legacyFile): string
	{
		Loc::loadLanguageFile(dirname(__DIR__, 7) . '/' . $legacyFile);

		return (string)Loc::getMessage($code);
	}

	//endregion
}
