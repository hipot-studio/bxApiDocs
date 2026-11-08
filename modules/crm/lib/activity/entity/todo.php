<?php

namespace Bitrix\Crm\Activity\Entity;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Activity\ToDo\CalendarEventOccupancyFields;
use Bitrix\Calendar\Rooms\AccessibilityManager;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\ActivityV2;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Mapper\LegacyInputMapper;
use Bitrix\Crm\V2\Internal\Service\Activity\Mapper\LegacyOutputMapper;
use Bitrix\Crm\V2\Internal\Service\Activity\Provider\ActivityProviderInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmActivityType;

/**
 * THE ONE DOMAIN ENTITY THAT WRITES THROUGH THE SERVICE OF V2 ([P8.T1], the pilot of the migration). While the
 * delegation flag is on, a to-do is created and updated by calling the internal service directly, and the source
 * of the call is stated as the native one - which is what tells the extracted step notifying the person
 * responsible from the transitional layer of the activity stream apart (ALG-06). With the flag off, and for every
 * other kind of activity, nothing changes: the write goes through `CCrmActivity` exactly as before.
 *
 * WHAT IS TYPED AND WHAT IS NOT. The service is given the typed input of an operation - the model of the
 * activity, its serialized fields, the columns of the storage and the context of the call - and the projection
 * producing it is the one the facade uses ({@see LegacyInputMapper}). It has to be that one: the values a to-do
 * is saved with arrive from the blocks of its editor as a field array of legacy (the additional fields), so a
 * second projection of the same array would be a second set of rules about it.
 *
 * WHAT STAYS ABOVE THE SERVICE. The check of the rights on the owner, the gate of a bound calendar event and the
 * guard against a busy meeting room are decisions of this layer and keep their place: only the write itself
 * travels.
 */
class ToDo extends BaseActivity
{
	public function isValidProviderId(string $providerId): bool
	{
		return $this->provider::getId() === Provider\ToDo\ToDo::getId() && $providerId === Provider\ToDo\ToDo::getId();
	}

	public function getProviderId(): string
	{
		return Provider\ToDo\ToDo::PROVIDER_ID;
	}

	public function getProviderTypeId(): string
	{
		return Provider\ToDo\ToDo::PROVIDER_TYPE_ID_DEFAULT;
	}

	public function save(array $options = [], $useCurrentSettings = false): Result
	{
		$this->tryAppendTags();
		$this->appendContextToOptions($options);

		$occupancyResult = $this->checkLocationOccupancy($options);
		if (!$occupancyResult->isSuccess())
		{
			return $occupancyResult;
		}

		return parent::save($options, $useCurrentSettings);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 */
	protected function performCreate(array $fields, array $options): Result
	{
		return $this->writesThroughTheServiceOfV2()
			? $this->createThroughTheServiceOfV2($fields, $options)
			: parent::performCreate($fields, $options)
		;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 */
	protected function performUpdate(array $fields, array $options): Result
	{
		return $this->writesThroughTheServiceOfV2()
			? $this->updateThroughTheServiceOfV2($fields, $options)
			: parent::performUpdate($fields, $options)
		;
	}

	private function writesThroughTheServiceOfV2(): bool
	{
		return Feature::enabled(ActivityV2::class);
	}

	/**
	 * Creates the to-do through the operation of the service.
	 *
	 * WHAT THE LEGACY CREATION OF A PROVIDER ACTIVITY STATED AND IS STATED HERE INSTEAD (LBS-2 C15): the activity
	 * is one of a provider, the identity of that provider is forced rather than taken from the caller, the kind of
	 * activity has to be one the provider really offers, and a record of the activity stream is registered unless
	 * the caller said otherwise. The rights on the owner have been checked above, so the operation itself performs
	 * no check - exactly as the legacy creation passed that decision on.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 */
	private function createThroughTheServiceOfV2(array $fields, array $options): Result
	{
		$result = new Result();

		$provider = Container::getInstance()->getActivityProviderRegistry()->get($this->getProviderId());
		$typeId = $this->getProviderTypeId();
		if ($provider === null || !self::offersTypeOfActivity($provider, $typeId))
		{
			return $result->addError(new Error('Invalid typeId: ' . $typeId));
		}

		$fields['TYPE_ID'] = CCrmActivityType::Provider;
		$fields['PROVIDER_ID'] = $this->getProviderId();
		$fields['PROVIDER_TYPE_ID'] = $typeId;
		$options['REGISTER_SONET_EVENT'] = (bool)($options['REGISTER_SONET_EVENT'] ?? true);

		$operationContext = LegacyInputMapper::operationContextOf(
			$fields,
			$options,
			checkPermissions: false,
			entrySource: ActivityOperationContext::ENTRY_SOURCE_NATIVE,
		);

		$serviceResult = Container::getInstance()->getActivityLifecycleService()->add(
			LegacyInputMapper::activityOf($fields, $operationContext),
			$operationContext,
		);

		$activityId = LegacyOutputMapper::activityIdOf($serviceResult);
		if ($activityId === null)
		{
			return $result->addErrors($serviceResult->getErrors());
		}

		return $result->setData(['id' => $activityId]);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 */
	private function updateThroughTheServiceOfV2(array $fields, array $options): Result
	{
		$operationContext = LegacyInputMapper::operationContextOf(
			$fields,
			$options,
			checkPermissions: $this->checkPermissions,
			entrySource: ActivityOperationContext::ENTRY_SOURCE_NATIVE,
		);

		$serviceResult = Container::getInstance()->getActivityLifecycleService()->update(
			(int)$this->getId(),
			LegacyInputMapper::activityOf($fields, $operationContext),
			$operationContext,
		);

		return (new Result())->addErrors($serviceResult->getErrors());
	}

	/**
	 * Whether the provider really offers the kind of activity this entity saves. The provider of the registry is
	 * asked, because on the path of V2 it is the only source of the rules of a to-do.
	 */
	private static function offersTypeOfActivity(ActivityProviderInterface $provider, string $typeId): bool
	{
		foreach ($provider->getTypes() as $type)
		{
			if (($type['providerTypeId'] ?? null) === $typeId)
			{
				return true;
			}
		}

		return false;
	}

	private function checkLocationOccupancy(array $options): Result
	{
		$result = new Result();

		if (!Loader::includeModule('calendar'))
		{
			return $result;
		}

		$additionalFields = $this->getAdditionalFields();
		if ((string)($additionalFields['LOCATION'] ?? '') === '')
		{
			return $result;
		}

		// Guard only saves that actually create or update the room booking. When the calendar event
		// is skipped (e.g. no available section) no reservation is written, so blocking here would be
		// a false positive.
		$activityFields = $additionalFields;
		$activityFields['CALENDAR_EVENT_ID'] = $this->getCalendarEventId();
		if (Provider\ToDo\ToDo::skipCalendarSync($activityFields, $options))
		{
			return $result;
		}

		$calendarEventId = $this->resolveCalendarEventId();
		$occupancyFields = CalendarEventOccupancyFields::build(
			$additionalFields,
			(int)$this->getResponsibleId(),
			$calendarEventId,
		);
		if ($occupancyFields === null)
		{
			return $result;
		}

		// Metadata-only edits (color/description/files) re-enrich the entity with the stored booking's
		// unchanged room and interval, then save. Running the occupancy query on every such save is
		// pure overhead: only creating a booking or moving its room/interval can introduce a conflict.
		if ($calendarEventId && $this->isSameAsStoredBooking($occupancyFields, (int)$calendarEventId))
		{
			return $result;
		}

		$isFree = AccessibilityManager::checkAccessibility(
			$occupancyFields->getLocation(),
			['fields' => $occupancyFields->getFields()],
		);
		if (!$isFree)
		{
			$result->addError(
				new Error(
					Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_LOCATION_BUSY'),
					'LOCATION_BUSY',
				),
			);
		}

		return $result;
	}

	private function resolveCalendarEventId(): ?int
	{
		if ($this->getCalendarEventId())
		{
			return $this->getCalendarEventId();
		}

		if ($this->getId())
		{
			$existedActivity = \CCrmActivity::GetByID($this->getId());
			if ($existedActivity && isset($existedActivity['CALENDAR_EVENT_ID']))
			{
				return (int)$existedActivity['CALENDAR_EVENT_ID'];
			}
		}

		return null;
	}

	/**
	 * Returns true when the requested room and interval match the already-saved calendar event, so
	 * the save cannot introduce a new overlap and the occupancy query can be skipped. Fails safe:
	 * any missing/uncomparable field means "not the same" and the guard runs.
	 */
	private function isSameAsStoredBooking(CalendarEventOccupancyFields $occupancyFields, int $calendarEventId): bool
	{
		$storedEvent = \Bitrix\Crm\Integration\Calendar::getEvent($calendarEventId, true);
		if (!is_array($storedEvent))
		{
			return false;
		}

		$requestedRoom = \Bitrix\Calendar\Rooms\Util::parseLocation($occupancyFields->getLocation());
		$storedRoom = \Bitrix\Calendar\Rooms\Util::parseLocation((string)($storedEvent['LOCATION'] ?? ''));
		if (
			(int)$requestedRoom['room_id'] !== (int)$storedRoom['room_id']
			|| (int)$requestedRoom['mrid'] !== (int)$storedRoom['mrid']
		)
		{
			return false;
		}

		$requestedFields = $occupancyFields->getFields();
		$requestedInterval = $this->bookingIntervalUtc(
			$requestedFields['DATE_FROM'] ?? null,
			$requestedFields['DATE_TO'] ?? null,
			$requestedFields['TZ_FROM'] ?? null,
		);
		$storedInterval = $this->bookingIntervalUtc(
			$storedEvent['DATE_FROM'] ?? null,
			$storedEvent['DATE_TO'] ?? null,
			$storedEvent['TZ_FROM'] ?? null,
		);

		return $requestedInterval !== null && $requestedInterval === $storedInterval;
	}

	/**
	 * @return array{0: int, 1: int}|null UTC start/end timestamps, or null when the interval is incomplete.
	 */
	private function bookingIntervalUtc(?string $dateFrom, ?string $dateTo, ?string $timezone): ?array
	{
		if (($dateFrom ?? '') === '' || ($dateTo ?? '') === '' || ($timezone ?? '') === '')
		{
			return null;
		}

		return [
			\Bitrix\Calendar\Util::getDateTimestampUtc(new DateTime($dateFrom), $timezone),
			\Bitrix\Calendar\Util::getDateTimestampUtc(new DateTime($dateTo), $timezone),
		];
	}

	private function tryAppendTags(): void
	{
		$additionalFields = $this->getAdditionalFields();

		if (
			empty($additionalFields['START_TIME'])
			|| empty($additionalFields['END_TIME'])
			|| empty($additionalFields['SETTINGS']['USERS'])
		)
		{
			return;
		}

		$calendarEventId = $this->resolveCalendarEventId();

		$userIds = $additionalFields['SETTINGS']['USERS'];
		$fromTimestampEvent = DateTime::createFromUserTime($additionalFields['START_TIME'])->getTimestamp();
		$toTimestampEvent = DateTime::createFromUserTime($additionalFields['END_TIME'])->getTimestamp();

		if ($this->hasOverlapEvent($userIds, $fromTimestampEvent, $toTimestampEvent, $calendarEventId))
		{
			$additionalFields['SETTINGS']['TAGS'] = ['OVERLAP_EVENT' => true];
			$this->setAdditionalFields($additionalFields);
		}
	}

	private function hasOverlapEvent(
		array $userIds,
		int   $fromTimestampEvent,
		int   $toTimestampEvent,
		?int $currentCalendarEventId = null
	): bool
	{
		$busyUsersIds = \Bitrix\Crm\Integration\Calendar::getBusyUsersIds(
			$userIds,
			$fromTimestampEvent,
			$toTimestampEvent,
			$currentCalendarEventId
		);

		return !empty($busyUsersIds);
	}

	private function appendContextToOptions(array &$options): void
	{
		$options['CONTEXT'] = $this->getContext();
	}
}
