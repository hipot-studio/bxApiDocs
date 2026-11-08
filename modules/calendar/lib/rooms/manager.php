<?php

namespace Bitrix\Calendar\Rooms;

use Bitrix\Calendar\Core\Event\EventSaveStateException;
use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Access\Model\SectionModel;
use Bitrix\Calendar\Access\SectionAccessController;
use Bitrix\Calendar\Integration\Pull\PushCommand;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Calendar\Internals\AccessTable;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Calendar\Internals\LocationTable;
use Bitrix\Calendar\Internals\SectionTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Calendar\UserSettings;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\EventManager;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;
use Throwable;

Loc::loadMessages(__FILE__);

class Manager
{
	public const TYPE = 'location';
	private const OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE = 300;
	private const ROOM_QUERY_CHUNK_SIZE = 300;
	private const RESERVATION_ROOM_QUERY_CHUNK_SIZE = 500;

	/** @var Room|null $room */
	private ?Room $room = null;
	/** @var Error|null $error */
	private ?Error $error = null;

	protected function __construct()
	{
	}

	/**
	 * @param Room $room
	 *
	 * @return Manager
	 */
	public static function createInstanceWithRoom(Room $room): Manager
	{
		return (new self())->setRoom($room);
	}

	/**
	 * @return Manager
	 */
	public static function createInstance(): Manager
	{
		return new self;
	}

	/**
	 * @param Room $room
	 *
	 * @return Manager
	 */
	private function setRoom(Room $room): Manager
	{
		$this->room = $room;

		return $this;
	}

	/**
	 * @param Error $error
	 *
	 * @return void
	 */
	private function addError(Error $error): void
	{
		$this->error = $error;
	}

	public function getError(): ?Error
	{
		return $this->error;
	}

	/**
	 * Creating Room in Location Calendar
	 *
	 * @return Manager
	 * @throws \Exception
	 */
	public function createRoom(): Manager
	{
		if ($this->error)
		{
			return $this;
		}

		$this->room->create();

		if ($this->room->getError())
		{
			$this->addError($this->room->getError());
		}

		return $this;
	}

	/**
	 * Updating data of room in Location calendar
	 *
	 * @return Manager
	 * @throws \Exception
	 */
	public function updateRoom(): Manager
	{
		if ($this->error)
		{
			return $this;
		}

		$this->room->update();

		if ($this->room->getError())
		{
			$this->addError($this->room->getError());
		}

		return $this;
	}

	/**
	 * Deleting room by id in Location calendar
	 *
	 * @return Manager
	 * @throws \Exception
	 */
	public function deleteRoom(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		if (!$this->room->getName())
		{
			$this->room->setName($this->getRoomName($this->room->getId()));
		}

		$this->room->delete();

		if ($this->room->getError())
		{
			$this->addError($this->room->getError());
		}

		return $this;
	}

	/**
	 * @return array|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getRoomsList(): ?array
	{
		$roomQuery = LocationTable::query()
			->setSelect([
				'LOCATION_ID' => 'ID',
				'NECESSITY',
	            'CAPACITY',
				'SECTION_ID',
				'CATEGORY_ID',
				'NAME' => 'SECTION.NAME',
				'COLOR' => 'SECTION.COLOR',
				'OWNER_ID' => 'SECTION.OWNER_ID',
				'CAL_TYPE' => 'SECTION.CAL_TYPE',
            ])
			->registerRuntimeField('SECTION',
               new ReferenceField(
                   'SECTION',
                   SectionTable::getEntity(),
                   Query\Join::on('ref.ID', 'this.SECTION_ID'),
                   ['join_type' => Query\Join::TYPE_INNER]
               )
			)
			->setOrder(['ID' => 'ASC'])
			->cacheJoins(true)
			->setCacheTtl(86400)
			->exec()
		;

		[$roomsId, $result] = self::prepareRoomsQueryData($roomQuery);

		if (empty($result))
		{
			\CCalendarSect::CreateDefault([
				'type' => self::TYPE,
				'ownerId' => 0
			]);
			LocationTable::cleanCache();

			return null;
		}

		$result = self::getRoomsAccess($roomsId, $result);

		foreach ($result as $room)
		{
			\CCalendarSect::HandlePermission($room);
		}

		return \CCalendarSect::GetSectionPermission($result);
	}

	/**
	 * @param int $id
	 * @param array $params
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getRoomById(int $id, array $params = []): array
	{
		$roomQuery = LocationTable::query()
			->setSelect([
				'LOCATION_ID' => 'ID',
				'NECESSITY',
				'CAPACITY',
				'SECTION_ID',
				'CATEGORY_ID',
				'NAME' => 'SECTION.NAME',
				'COLOR' => 'SECTION.COLOR',
				'OWNER_ID' => 'SECTION.OWNER_ID',
				'CAL_TYPE' => 'SECTION.CAL_TYPE',
			])
			->where('SECTION.ID', $id)
			->registerRuntimeField('SECTION',
				new ReferenceField(
					'SECTION',
					SectionTable::getEntity(),
					Query\Join::on('ref.ID', 'this.SECTION_ID'),
					['join_type' => Query\Join::TYPE_INNER]
				)
			)
			->cacheJoins(true)
			->setCacheTtl(86400)
			->exec()
		;

		[$roomsId, $result] = self::prepareRoomsQueryData($roomQuery);
		$result = self::getRoomsAccess($roomsId, $result);

		foreach ($result as $room)
		{
			\CCalendarSect::HandlePermission($room);
		}

		$applyPermission = $params['checkPermission'] ?? true;
		if ($applyPermission !== false)
		{
			return \CCalendarSect::GetSectionPermission($result);
		}

		return [...$result];
	}

	public static function getRoomsByIds(array $roomIds, array $params = []): array
	{
		$roomIds = array_values(array_unique(array_filter(
			array_map('intval', $roomIds),
			static fn(int $roomId): bool => $roomId > 0,
		)));
		sort($roomIds, SORT_NUMERIC);
		if ($roomIds === [])
		{
			return [];
		}

		$result = [];
		foreach (array_chunk($roomIds, self::ROOM_QUERY_CHUNK_SIZE) as $roomIdChunk)
		{
			$roomQuery = LocationTable::query()
				->setSelect([
					'LOCATION_ID' => 'ID',
					'NECESSITY',
					'CAPACITY',
					'SECTION_ID',
					'CATEGORY_ID',
					'NAME' => 'SECTION.NAME',
					'COLOR' => 'SECTION.COLOR',
					'OWNER_ID' => 'SECTION.OWNER_ID',
					'CAL_TYPE' => 'SECTION.CAL_TYPE',
				])
				->whereIn('SECTION.ID', $roomIdChunk)
				->registerRuntimeField(
					'SECTION',
					new ReferenceField(
						'SECTION',
						SectionTable::getEntity(),
						Query\Join::on('ref.ID', 'this.SECTION_ID'),
						['join_type' => Query\Join::TYPE_INNER],
					),
				)
				->setOrder(['ID' => 'ASC'])
				->cacheJoins(true)
				->setCacheTtl(86400)
				->exec()
			;
			[, $roomChunk] = self::prepareRoomsQueryData($roomQuery);
			$result += $roomChunk;
		}

		$result = self::getRoomsAccess(array_keys($result), $result);
		foreach ($result as $room)
		{
			\CCalendarSect::HandlePermission($room);
		}

		$applyPermission = $params['checkPermission'] ?? true;
		if ($applyPermission !== false)
		{
			return \CCalendarSect::GetSectionPermission($result);
		}

		return [...$result];
	}

	public static function canExposeOccupancyDates(array $occupancyData): bool
	{
		$sectionIds = array_values(array_unique(array_filter(
			array_map('intval', (array)($occupancyData['sectionIds'] ?? [])),
			static fn(int $sectionId): bool => $sectionId > 0,
		)));
		if ($sectionIds === [])
		{
			return false;
		}

		foreach ($sectionIds as $sectionId)
		{
			try
			{
				$room = self::getRoomById($sectionId)[0] ?? null;
			}
			catch (\Throwable)
			{
				return false;
			}

			if (empty($room['NAME']) || empty($room['PERM']['view_full']))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns an active reservation visible to the saving actor.
	 *
	 * @return array
	 */
	public static function assertReservationAvailable(
		int $bookingId,
		int $roomId,
		bool $checkPermission = true,
		?int $userId = null,
		?bool $checkRoomPermission = null,
		mixed $newLocation = null,
	): array
	{
		$bookings = self::assertReservationsAvailable([
			['bookingId' => $bookingId, 'roomId' => $roomId, 'newLocation' => $newLocation],
		], $checkPermission, $userId, $checkRoomPermission);

		return $bookings[$bookingId];
	}

	/**
	 * Validates the complete reservation set before its caller starts mutating events.
	 *
	 * @param list<array{bookingId: int, roomId: int, newLocation: mixed}> $reservations
	 * @return array<int, array>
	 */
	public static function assertReservationsAvailable(
		array $reservations,
		bool $checkPermission = true,
		?int $userId = null,
		?bool $checkRoomPermission = null,
	): array
	{
		$userId ??= \CCalendar::GetCurUserId();
		$checkRoomPermission ??= $checkPermission;
		$bookingIds = [];
		foreach ($reservations as $reservation)
		{
			$bookingId = (int)$reservation['bookingId'];
			if ($bookingId <= 0 || (int)$reservation['roomId'] <= 0)
			{
				throw EventSaveStateException::roomReservationNotAvailable();
			}
			$bookingIds[$bookingId] = true;
		}
		$bookings = [];
		foreach (array_chunk(array_keys($bookingIds), self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $bookingIdChunk)
		{
			$events = \CCalendarEvent::GetList([
				'arFilter' => ['ID' => $bookingIdChunk, 'DELETED' => 'N', 'CAL_TYPE' => self::TYPE],
				'parseRecursion' => false,
				'checkPermissions' => $checkPermission,
				'userId' => $userId,
				'setDefaultLimit' => false,
				'skipCache' => true,
			]);
			foreach ($events as $booking)
			{
				$bookings[(int)$booking['ID']] = $booking;
			}
		}
		$roomIds = [];
		foreach ($reservations as $reservation)
		{
			$roomId = (int)$reservation['roomId'];
			$booking = $bookings[(int)$reservation['bookingId']] ?? null;
			if (
				!is_array($booking)
				|| ($booking['CAL_TYPE'] ?? null) !== self::TYPE
				|| (int)($booking['SECTION_ID'] ?? $booking['SECT_ID'] ?? 0) !== $roomId
			)
			{
				throw EventSaveStateException::roomReservationNotAvailable();
			}
			$roomIds[$roomId] = true;
		}
		$rooms = self::assertRoomsStructurallyAvailable(array_keys($roomIds));
		if ($checkRoomPermission)
		{
			$permissionRoomIds = [];
			foreach ($reservations as $reservation)
			{
				$roomId = (int)$reservation['roomId'];
				if (!self::isOwnedReservationRelease(
					$bookings[(int)$reservation['bookingId']], $userId, $roomId,
					$reservation['newLocation'] ?? null,
				))
				{
					$permissionRoomIds[$roomId] = true;
				}
			}
			self::assertReservationRoomPermissions(array_intersect_key($rooms, $permissionRoomIds), $userId);
		}

		return $bookings;
	}

	/** @return array<int, array> */
	private static function assertRoomsStructurallyAvailable(array $roomIds): array
	{
		try
		{
			$rooms = [];
			foreach (array_chunk($roomIds, self::RESERVATION_ROOM_QUERY_CHUNK_SIZE) as $roomIdChunk)
			{
				$rows = LocationTable::query()
					->setSelect(['SECTION_ID', 'NAME' => 'SECTION.NAME',
						'CAL_TYPE' => 'SECTION.CAL_TYPE', 'OWNER_ID' => 'SECTION.OWNER_ID'])
					->registerRuntimeField('SECTION', new ReferenceField(
						'SECTION', SectionTable::getEntity(), Query\Join::on('ref.ID', 'this.SECTION_ID'),
						['join_type' => Query\Join::TYPE_INNER],
					))
					->whereIn('SECTION_ID', $roomIdChunk)
					->where('SECTION.CAL_TYPE', self::TYPE)
					->where('SECTION.ACTIVE', 'Y')
					->setLimit(self::RESERVATION_ROOM_QUERY_CHUNK_SIZE + 1)
					->exec()
				;
				$availableRoomIds = [];
				while ($room = $rows->fetch())
				{
					if (trim(Emoji::decode((string)$room['NAME'])) !== '')
					{
						$roomId = (int)$room['SECTION_ID'];
						$availableRoomIds[] = $roomId;
						$rooms[$roomId] = $room;
					}
				}
				if (array_diff($roomIdChunk, $availableRoomIds) !== [])
				{
					throw EventSaveStateException::roomReservationNotAvailable();
				}
			}

			return $rooms;
		}
		catch (Throwable)
		{
			throw EventSaveStateException::roomReservationNotAvailable();
		}
	}

	private static function isOwnedReservationRelease(
		array $booking,
		int $userId,
		int $oldRoomId,
		mixed $newLocation,
	): bool
	{
		if (!is_string($newLocation) || $userId <= 0 || (int)($booking['CREATED_BY'] ?? 0) !== $userId)
		{
			return false;
		}
		$location = Util::parseLocation($newLocation);

		return $location['mrid'] === false
			&& (
				$location['room_id'] === false
				|| ((int)$location['room_id'] > 0 && (int)$location['room_id'] !== $oldRoomId)
			);
	}

	private static function assertReservationRoomPermissions(array $rooms, int $userId): void
	{
		try
		{
			$controller = new SectionAccessController($userId);
			foreach ($rooms as $roomId => $room)
			{
				$model = SectionModel::createNew()
					->setId((int)$roomId)->setType((string)$room['CAL_TYPE'])->setOwnerId((int)$room['OWNER_ID']);
				if (!$controller->check(ActionDictionary::ACTION_SECTION_EVENT_VIEW_TIME, $model)
					|| !$controller->check(ActionDictionary::ACTION_SECTION_EVENT_VIEW_FULL, $model))
				{
					throw EventSaveStateException::roomReservationNotAvailable();
				}
			}
		}
		catch (Throwable)
		{
			throw EventSaveStateException::roomReservationNotAvailable();
		}
	}

	public static function setLocationWithReservationValidation(
		mixed $oldLocation,
		mixed $newLocation,
		array $params,
		int $userId,
		bool $checkRoomPermission,
		?OccupancyLock $lock,
		bool &$mutationStarted,
	): mixed
	{
		$reservation = Util::parseLocation($oldLocation);
		$checkPermission = ($params['checkPermission'] ?? null) !== false;
		$currentBooking = null;
		if ($reservation['room_event_id'])
		{
			$currentBooking = self::assertReservationAvailable(
				(int)$reservation['room_event_id'], (int)$reservation['room_id'],
				$checkPermission, $userId, $checkRoomPermission, $newLocation,
			);
		}
		$params['parentParams']['occupancyLock'] = $lock;
		$newReservation = Util::parseLocation($newLocation);
		$reserveUserId = (int)($params['parentParams']['userId'] ?? \CCalendar::GetCurUserId());
		// Reuse only this frame's authoritative read, before any reservation mutation or callbacks.
		if (
			$currentBooking !== null
			&& (int)($currentBooking['ID'] ?? 0) === (int)$reservation['room_event_id']
			&& (int)($currentBooking['SECTION_ID'] ?? 0) === (int)$reservation['room_id']
			&& is_string($oldLocation)
			&& is_string($newLocation)
			&& $reservation['mrid'] === false
			&& $reservation['room_id'] > 0
			&& $newReservation['mrid'] === false
			&& $newReservation['room_id'] > 0
			&& ($params['parentParams']['arFields']['LOCATION']['OLD'] ?? null) === $oldLocation
			&& $userId === $reserveUserId
			&& $checkRoomPermission === $checkPermission
		)
		{
			$mutationStarted = true;
			$timezoneEnabled = \CTimeZone::Enabled();
			if ($timezoneEnabled)
			{
				\CTimeZone::Disable();
			}
			try
			{
				$bookingId = self::reserveRoomWithValidatedBooking([
					'room_id' => $newReservation['room_id'],
					'room_event_id' => $reservation['room_event_id'],
					'parentParams' => $params['parentParams'],
					'checkPermission' => $params['checkPermission'] ?? null,
				], $currentBooking);
				if (!$bookingId)
				{
					throw new \RuntimeException('Unable to update the meeting room reservation.');
				}

				return 'calendar_' . $newReservation['room_id'] . '_' . $bookingId;
			}
			finally
			{
				if ($timezoneEnabled)
				{
					\CTimeZone::Enable();
				}
			}
		}
		$mutationStarted = true;

		return Util::setLocation($oldLocation, $newLocation, $params);
	}

	/**
	 * @param array $params
	 *
	 * @return int|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function reserveRoom(array $params = []): ?int
	{
		$params['checkPermission'] = $params['checkPermission'] ?? null;
		$params['room_id'] = $params['room_id'] ?? null;
		$bookingId = (int)($params['room_event_id'] ?? 0);
		$currentBooking = null;
		if ($bookingId > 0)
		{
			$oldLocation = Util::parseLocation($params['parentParams']['arFields']['LOCATION']['OLD'] ?? null);
			$oldRoomId = (int)($oldLocation['room_id'] ?: $params['room_id']);
			$userId = (int)($params['parentParams']['userId'] ?? \CCalendar::GetCurUserId());
			$checkPermission = $params['checkPermission'] !== false;
			$currentBooking = self::assertReservationAvailable(
				$bookingId, $oldRoomId, $checkPermission, $userId, $checkPermission,
				'calendar_' . (int)$params['room_id'],
			);
		}

		return self::reserveRoomWithValidatedBooking($params, $currentBooking);
	}

	private static function reserveRoomWithValidatedBooking(array $params, ?array $currentBooking): ?int
	{
		$bookingId = (int)($params['room_event_id'] ?? 0);
		$roomList = self::getRoomById((int)$params['room_id'], ['checkPermission' => $params['checkPermission']]);

		if (
			!$roomList || empty($roomList[0])
			|| empty($roomList[0]['NAME'])
			|| (
				empty($roomList[0]['PERM']['view_full'])
				&& $params['checkPermission'] !== false
			)
		)
		{
			if ($bookingId > 0)
			{
				throw EventSaveStateException::roomReservationNotAvailable();
			}

			return null;
		}

		$createdBy = $params['parentParams']['arFields']['CREATED_BY']
			?? $params['parentParams']['arFields']['MEETING_HOST']
			?? null
		;

		$arFields = [
			'ID' => $params['room_event_id'] ?? null,
			'SECTIONS' => $params['room_id'] ?? null,
			'DATE_FROM' => $params['parentParams']['arFields']['DATE_FROM'] ?? null,
			'DATE_TO' => $params['parentParams']['arFields']['DATE_TO'] ?? null,
			'TZ_FROM' => $params['parentParams']['arFields']['TZ_FROM'] ?? null,
			'TZ_TO' => $params['parentParams']['arFields']['TZ_TO'] ?? null,
			'SKIP_TIME' => $params['parentParams']['arFields']['SKIP_TIME'] ?? null,
			'RRULE' => $params['parentParams']['arFields']['RRULE'] ?? null,
			'EXDATE' => $params['parentParams']['arFields']['EXDATE'] ?? null,
		];

		if (!$params['room_event_id'])
		{
			$arFields['CREATED_BY'] = $createdBy;
			$arFields['NAME'] = \CCalendar::GetUserName($createdBy);
			$arFields['CAL_TYPE'] = self::TYPE;
		}

		return \CCalendarEvent::Edit([
			'arFields' => $arFields,
			'currentEvent' => $currentBooking,
			'sendInvitations' => false,
			'sendEditNotification' => false,
		]);
	}

	/**
	 * @param array $params
	 *
	 * Cancel booking of room
	 *
	 * @return Manager
	 * @throws \Bitrix\Main\ObjectException
	 */
	public function cancelBooking(array $params = []): Manager
	{
		$params = [
			'recursion_mode' => $params['recursion_mode'] ?? null,
			'parent_event_id' => $params['parent_event_id'] ?? null,
			'section_id' => $params['section_id'] ?? null,
			'current_event_date_from' => $params['current_event_date_from'] ?? null,
			'current_event_date_to' => $params['current_event_date_to'] ?? null,
			'owner_id' => $params['owner_id'] ?? null,
		];

		if($this->getError() !== null)
		{
			return $this;
		}

		if($params['recursion_mode'] === 'all' || $params['recursion_mode'] === 'next')
		{
			$event = \CCalendarEvent::GetById($params['parent_event_id']);

			$params['frequency'] = $event['RRULE']['FREQ'] ?? null;
			if($params['recursion_mode'] === 'all')
			{
				$params['current_event_date_from'] = $event['DATE_FROM'] ?? null;
				$params['current_event_date_to'] = $event['DATE_TO'] ?? null;
			}
		}

		$result = \CCalendar::SaveEventEx([
			'recursionEditMode' => $params['recursion_mode'],
			'currentEventDateFrom' => $params['current_event_date_from'],
			'checkPermission' => false,
			'sendInvitations' => false,
			'userId' => $params['owner_id'],
			'arFields' => [
				'ID' => $params['parent_event_id'],
				'DATE_FROM' => $params['current_event_date_from'],
				'DATE_TO' => $params['current_event_date_to'],
				'LOCATION' => '',
			],
		]);

		$params['event_id'] = $result['recEventId'] ?? $result['id'] ?? null;

		$this->sendCancelBookingNotification($params);
		return $this;
	}

	private function sendCancelBookingNotification(array $params): void
	{
		$params = [
			'section_id' => $params['section_id'],
			'event_id' => $params['event_id'],
			'owner_id' => $params['owner_id'],
			'current_event_date_from' => $params['current_event_date_from'],
			'recursion_mode' => $params['recursion_mode'],
		];

		$section = \CCalendarSect::GetById($params['section_id']);
		$userId = \CCalendar::GetCurUserId();
		$event = \CCalendarEvent::GetById($params['event_id'], false);

		\CCalendarNotify::Send([
			'eventId' => $params['event_id'],
			'mode' => 'cancel_booking',
			'location' => $section['NAME'] ?? null,
			'locationId' => $params['section_id'],
			'guestId' => $params['owner_id'],
			'userId' => $userId,
			'from' => $params['current_event_date_from'],
			'eventName' => $event['NAME'] ?? null,
			'recursionMode' => $params['recursion_mode'],
			'fields' => $event,
		]);
	}

	/**
	 * @param array $params
	 *
	 * Deleting event from calendar location
	 *
	 * @return bool|string
	 */
	public static function releaseRoom(array $params = [])
	{
		return \CCalendar::DeleteEvent(
			(int)($params['room_event_id'] ?? null),
			false,
			[
				'checkPermissions' => false,
				'markDeleted' => false
			]
		);
	}

	/**
	 * @return Manager
	 *
	 * Clears cache for updating list of rooms on the page
	 */
	public function clearCache(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		\CCalendarSect::SetClearOperationCache(true);
		\CCalendar::clearCache([
			'section_list',
			'event_list'
		]);
		LocationTable::cleanCache();

		return $this;
	}

	/**
	 * @return Manager
	 */
	public function cleanAccessTable(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		\CCalendarSect::CleanAccessTable($this->room->getId());

		return $this;
	}

	/**
	 * @param int $id
	 *
	 * Setting id of new event in user calendar
	 * for event in location calendar
	 * @param string|null $location
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function setEventIdForLocation(int $id, ?string $location = null): bool
	{
		if (empty($location))
		{
			$event = EventTable::query()
				->setSelect(['LOCATION'])
				->where('ID', $id)
				->exec()->fetch()
			;

			$location = $event['LOCATION'] ?? null;
		}

		if (!empty($location))
		{
			$parsedLocation = Util::parseLocation($location);
			if ($parsedLocation['room_id'] && $parsedLocation['room_event_id'])
			{
				return EventTable::update($parsedLocation['room_event_id'], [
					'PARENT_ID' => $id,
				])->isSuccess();
			}
		}

		return true;
	}

	public static function createOccupancySnapshot(int $mainEventId, mixed $oldLocation): array
	{
		$mainEvent = $mainEventId > 0
			? EventTable::getByPrimary($mainEventId)->fetch()
			: null
		;
		$roomEvents = [];
		if ($mainEventId > 0)
		{
			$roomEvents = EventTable::query()
				->setSelect(['*'])
				->where('PARENT_ID', $mainEventId)
				->where('CAL_TYPE', self::TYPE)
				->exec()
				->fetchAll()
			;
		}

		$oldRoomEventId = (int)(Util::parseLocation($oldLocation)['room_event_id'] ?? 0);
		$roomEventIndex = array_column($roomEvents, null, 'ID');
		if ($oldRoomEventId > 0 && !isset($roomEventIndex[$oldRoomEventId]))
		{
			$oldRoomEvent = EventTable::getByPrimary($oldRoomEventId)->fetch();
			if ($oldRoomEvent)
			{
				$roomEvents[] = $oldRoomEvent;
			}
		}

		return [
			'mainEvent' => $mainEvent ?: null,
			'location' => $mainEvent['LOCATION'] ?? null,
			'roomEvents' => array_column($roomEvents, null, 'ID'),
		];
	}

	public static function createOccupancySnapshots(array $mainEventIds, array $oldLocations = []): array
	{
		$mainEventIds = array_values(array_unique(array_filter(
			array_map('intval', $mainEventIds),
			static fn(int $eventId): bool => $eventId > 0,
		)));
		if ($mainEventIds === [])
		{
			return [];
		}

		$mainEventsById = [];
		foreach (array_chunk($mainEventIds, self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $eventIdChunk)
		{
			$mainEvents = EventTable::query()
				->setSelect(['*'])
				->whereIn('ID', $eventIdChunk)
				->exec()
				->fetchAll()
			;
			foreach ($mainEvents as $mainEvent)
			{
				$mainEventsById[(int)$mainEvent['ID']] = $mainEvent;
			}
		}

		$roomEventsByParentId = [];
		$roomEventsById = [];
		foreach (array_chunk($mainEventIds, self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $eventIdChunk)
		{
			$roomEvents = EventTable::query()
				->setSelect(['*'])
				->whereIn('PARENT_ID', $eventIdChunk)
				->where('CAL_TYPE', self::TYPE)
				->exec()
				->fetchAll()
			;
			foreach ($roomEvents as $roomEvent)
			{
				$roomEventId = (int)$roomEvent['ID'];
				$roomEventsById[$roomEventId] = $roomEvent;
				$roomEventsByParentId[(int)$roomEvent['PARENT_ID']][$roomEventId] = $roomEvent;
			}
		}

		$oldRoomEventIds = [];
		foreach ($oldLocations as $oldLocation)
		{
			$roomEventId = (int)(Util::parseLocation($oldLocation)['room_event_id'] ?? 0);
			if ($roomEventId > 0 && !isset($roomEventsById[$roomEventId]))
			{
				$oldRoomEventIds[$roomEventId] = $roomEventId;
			}
		}

		if ($oldRoomEventIds !== [])
		{
			foreach (array_chunk(array_values($oldRoomEventIds), self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $roomEventIdChunk)
			{
				$oldRoomEvents = EventTable::query()
					->setSelect(['*'])
					->whereIn('ID', $roomEventIdChunk)
					->where('CAL_TYPE', self::TYPE)
					->exec()
					->fetchAll()
				;
				foreach ($oldRoomEvents as $oldRoomEvent)
				{
					$roomEventsById[(int)$oldRoomEvent['ID']] = $oldRoomEvent;
				}
			}
		}

		$snapshots = [];
		foreach ($mainEventIds as $mainEventId)
		{
			$mainEvent = $mainEventsById[$mainEventId] ?? null;
			$roomEventsForMainEvent = $roomEventsByParentId[$mainEventId] ?? [];
			$oldRoomEventId = (int)(Util::parseLocation($oldLocations[$mainEventId] ?? null)['room_event_id'] ?? 0);
			if ($oldRoomEventId > 0 && !isset($roomEventsForMainEvent[$oldRoomEventId]) && isset($roomEventsById[$oldRoomEventId]))
			{
				$roomEventsForMainEvent[$oldRoomEventId] = $roomEventsById[$oldRoomEventId];
			}

			$snapshots[$mainEventId] = [
				'mainEvent' => $mainEvent ?: null,
				'location' => $mainEvent['LOCATION'] ?? null,
				'roomEvents' => $roomEventsForMainEvent,
			];
		}

		return $snapshots;
	}

	public static function restoreOccupancySnapshot(
		array $snapshot,
		mixed $currentLocation,
		int $currentMainEventId
	): bool
	{
		$isRestored = true;
		$roomEvents = is_array($snapshot['roomEvents'] ?? null) ? $snapshot['roomEvents'] : [];
		$currentRoomEventIds = [];

		$currentRoomEventId = (int)(Util::parseLocation($currentLocation)['room_event_id'] ?? 0);
		if ($currentRoomEventId > 0)
		{
			$currentRoomEventIds[] = $currentRoomEventId;
		}
		if ($currentMainEventId > 0)
		{
			$currentRoomEventIds = [
				...$currentRoomEventIds,
				...array_map(
					'intval',
					array_column(EventTable::query()
						->setSelect(['ID'])
						->where('PARENT_ID', $currentMainEventId)
						->where('CAL_TYPE', self::TYPE)
						->exec()
						->fetchAll(), 'ID'),
				),
			];
		}

		foreach (array_unique($currentRoomEventIds) as $roomEventId)
		{
			if (!isset($roomEvents[$roomEventId]))
			{
				$isRestored = EventTable::delete($roomEventId)->isSuccess() && $isRestored;
			}
		}

		foreach ($roomEvents as $roomEvent)
		{
			$isRestored = self::restoreEventRow($roomEvent) && $isRestored;
		}

		$mainEvent = $snapshot['mainEvent'] ?? null;
		if (is_array($mainEvent))
		{
			$isRestored = self::restoreEventRow($mainEvent) && $isRestored;
		}
		elseif ($currentMainEventId > 0 && EventTable::getByPrimary($currentMainEventId)->fetch())
		{
			$isRestored = EventTable::delete($currentMainEventId)->isSuccess() && $isRestored;
		}

		return $isRestored && self::isOccupancySnapshotRestored($snapshot, $currentMainEventId);
	}

	public static function restoreOccupancySnapshots(array $snapshots): bool
	{
		$isRestored = true;
		$validSnapshots = [];
		$mainEventIds = [];
		$expectedRoomEventIds = [];
		foreach ($snapshots as $snapshot)
		{
			if (!is_array($snapshot))
			{
				$isRestored = false;
				continue;
			}

			$mainEventId = (int)($snapshot['mainEvent']['ID'] ?? 0);
			if ($mainEventId <= 0)
			{
				$isRestored = false;
				continue;
			}

			$validSnapshots[] = $snapshot;
			$mainEventIds[$mainEventId] = $mainEventId;
			foreach ($snapshot['roomEvents'] ?? [] as $roomEvent)
			{
				$roomEventId = (int)($roomEvent['ID'] ?? 0);
				if ($roomEventId > 0)
				{
					$expectedRoomEventIds[$roomEventId] = $roomEventId;
				}
			}
		}

		if ($validSnapshots === [])
		{
			return $isRestored;
		}

		try
		{
			[
				$currentMainEvents,
				$currentRoomEventsByParentId,
				$currentRoomEventsById,
			] = self::loadCurrentOccupancyRows(
				array_values($mainEventIds),
				array_values($expectedRoomEventIds),
			);

			$currentLocationRoomEventIds = [];
			foreach ($currentMainEvents as $currentMainEvent)
			{
				$roomEventId = (int)(Util::parseLocation($currentMainEvent['LOCATION'] ?? null)['room_event_id'] ?? 0);
				if ($roomEventId > 0 && !isset($currentRoomEventsById[$roomEventId]))
				{
					$currentLocationRoomEventIds[$roomEventId] = $roomEventId;
				}
			}

			if ($currentLocationRoomEventIds !== [])
			{
				[
					$currentMainEvents,
					$currentRoomEventsByParentId,
					$currentRoomEventsById,
				] = self::loadCurrentOccupancyRows(
					array_values($mainEventIds),
					[
						...array_values($expectedRoomEventIds),
						...array_values($currentLocationRoomEventIds),
					],
				);
			}

			foreach ($validSnapshots as $snapshot)
			{
				$mainEvent = $snapshot['mainEvent'];
				$mainEventId = (int)$mainEvent['ID'];
				$roomEvents = is_array($snapshot['roomEvents'] ?? null) ? $snapshot['roomEvents'] : [];
				$currentRoomEventIds = array_keys($currentRoomEventsByParentId[$mainEventId] ?? []);
				$currentRoomEventId = (int)(Util::parseLocation(
					$currentMainEvents[$mainEventId]['LOCATION'] ?? null,
				)['room_event_id'] ?? 0);
				if ($currentRoomEventId > 0)
				{
					$currentRoomEventIds[] = $currentRoomEventId;
				}

				foreach (array_unique($currentRoomEventIds) as $roomEventId)
				{
					if (!isset($roomEvents[$roomEventId]))
					{
						$isRestored = EventTable::delete($roomEventId)->isSuccess() && $isRestored;
					}
				}

				foreach ($roomEvents as $roomEvent)
				{
					$roomEventId = (int)($roomEvent['ID'] ?? 0);
					$isRestored = self::restoreEventRow(
						$roomEvent,
						$currentRoomEventsById[$roomEventId] ?? [],
					) && $isRestored;
				}

				$isRestored = self::restoreEventRow(
					$mainEvent,
					$currentMainEvents[$mainEventId] ?? [],
				) && $isRestored;
			}
		}
		catch (\Throwable)
		{
			return false;
		}

		try
		{
			if (!self::areOccupancySnapshotsRestored($validSnapshots))
			{
				$isRestored = false;
			}
		}
		catch (\Throwable)
		{
			$isRestored = false;
		}

		return $isRestored;
	}

	private static function loadCurrentOccupancyRows(array $mainEventIds, array $additionalRoomEventIds = []): array
	{
		$mainEventsById = [];
		foreach (array_chunk($mainEventIds, self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $eventIdChunk)
		{
			$mainEvents = EventTable::query()
				->setSelect(['*'])
				->whereIn('ID', $eventIdChunk)
				->exec()
				->fetchAll()
			;
			foreach ($mainEvents as $mainEvent)
			{
				$mainEventsById[(int)$mainEvent['ID']] = $mainEvent;
			}
		}

		$roomEventsByParentId = [];
		$roomEventsById = [];
		foreach (array_chunk($mainEventIds, self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $eventIdChunk)
		{
			$roomEvents = EventTable::query()
				->setSelect(['*'])
				->whereIn('PARENT_ID', $eventIdChunk)
				->where('CAL_TYPE', self::TYPE)
				->exec()
				->fetchAll()
			;
			foreach ($roomEvents as $roomEvent)
			{
				$roomEventId = (int)$roomEvent['ID'];
				$roomEventsById[$roomEventId] = $roomEvent;
				$roomEventsByParentId[(int)$roomEvent['PARENT_ID']][$roomEventId] = $roomEvent;
			}
		}

		$roomEventIdsToLoad = array_values(array_unique(array_filter(array_map(
			'intval',
			$additionalRoomEventIds,
		))));
		$roomEventIdsToLoad = array_values(array_diff($roomEventIdsToLoad, array_keys($roomEventsById)));
		if ($roomEventIdsToLoad !== [])
		{
			foreach (array_chunk($roomEventIdsToLoad, self::OCCUPANCY_SNAPSHOT_QUERY_CHUNK_SIZE) as $roomEventIdChunk)
			{
				$roomEvents = EventTable::query()
					->setSelect(['*'])
					->whereIn('ID', $roomEventIdChunk)
					->exec()
					->fetchAll()
				;
				foreach ($roomEvents as $roomEvent)
				{
					$roomEventsById[(int)$roomEvent['ID']] = $roomEvent;
				}
			}
		}

		return [$mainEventsById, $roomEventsByParentId, $roomEventsById];
	}

	private static function areOccupancySnapshotsRestored(array $snapshots): bool
	{
		$mainEventIds = [];
		$expectedRoomEventIds = [];
		foreach ($snapshots as $snapshot)
		{
			$mainEventId = (int)($snapshot['mainEvent']['ID'] ?? 0);
			if ($mainEventId <= 0)
			{
				return false;
			}

			$mainEventIds[$mainEventId] = $mainEventId;
			foreach ($snapshot['roomEvents'] ?? [] as $roomEvent)
			{
				$roomEventId = (int)($roomEvent['ID'] ?? 0);
				if ($roomEventId > 0)
				{
					$expectedRoomEventIds[$roomEventId] = $roomEventId;
				}
			}
		}

		[
			$currentMainEvents,
			$currentRoomEventsByParentId,
			$currentRoomEventsById,
		] = self::loadCurrentOccupancyRows(
			array_values($mainEventIds),
			array_values($expectedRoomEventIds),
		);

		$currentLocationRoomEventIds = [];
		foreach ($currentMainEvents as $currentMainEvent)
		{
			$roomEventId = (int)(Util::parseLocation($currentMainEvent['LOCATION'] ?? null)['room_event_id'] ?? 0);
			if ($roomEventId > 0 && !isset($currentRoomEventsById[$roomEventId]))
			{
				$currentLocationRoomEventIds[$roomEventId] = $roomEventId;
			}
		}
		if ($currentLocationRoomEventIds !== [])
		{
			[
				$currentMainEvents,
				$currentRoomEventsByParentId,
				$currentRoomEventsById,
			] = self::loadCurrentOccupancyRows(
				array_values($mainEventIds),
				[
					...array_values($expectedRoomEventIds),
					...array_values($currentLocationRoomEventIds),
				],
			);
		}

		foreach ($snapshots as $snapshot)
		{
			$mainEvent = $snapshot['mainEvent'];
			$mainEventId = (int)$mainEvent['ID'];
			$currentMainEvent = $currentMainEvents[$mainEventId] ?? null;
			if (
				!is_array($currentMainEvent)
				|| !self::isOccupancyRowRestored($mainEvent, $currentMainEvent)
			)
			{
				return false;
			}

			foreach ($snapshot['roomEvents'] ?? [] as $roomEvent)
			{
				$roomEventId = (int)($roomEvent['ID'] ?? 0);
				$currentRoomEvent = $currentRoomEventsById[$roomEventId] ?? null;
				if (
					!is_array($currentRoomEvent)
					|| !self::isOccupancyRowRestored($roomEvent, $currentRoomEvent)
				)
				{
					return false;
				}
			}

			$currentRoomEventIds = array_keys($currentRoomEventsByParentId[$mainEventId] ?? []);
			$currentRoomEventId = (int)(Util::parseLocation($currentMainEvent['LOCATION'] ?? null)['room_event_id'] ?? 0);
			if ($currentRoomEventId > 0)
			{
				$currentRoomEventIds[] = $currentRoomEventId;
			}
			$expectedRoomEventIdsForMainEvent = array_keys($snapshot['roomEvents'] ?? []);
			$currentRoomEventIds = array_values(array_unique(array_map('intval', $currentRoomEventIds)));
			$expectedRoomEventIdsForMainEvent = array_values(array_unique(array_map('intval', $expectedRoomEventIdsForMainEvent)));
			sort($currentRoomEventIds, SORT_NUMERIC);
			sort($expectedRoomEventIdsForMainEvent, SORT_NUMERIC);
			if ($currentRoomEventIds !== $expectedRoomEventIdsForMainEvent)
			{
				return false;
			}
		}

		return true;
	}

	private static function restoreEventRow(array $event, ?array $currentEvent = null): bool
	{
		$eventId = (int)($event['ID'] ?? 0);
		if ($eventId <= 0)
		{
			return false;
		}

		$currentEvent ??= EventTable::getByPrimary($eventId)->fetch() ?: [];
		if ($currentEvent !== [])
		{
			unset($event['ID']);

			return EventTable::update($eventId, $event)->isSuccess();
		}

		return EventTable::add($event)->isSuccess();
	}

	private static function isOccupancySnapshotRestored(array $snapshot, int $currentMainEventId): bool
	{
		$mainEvent = $snapshot['mainEvent'] ?? null;
		if (is_array($mainEvent))
		{
			$currentMainEvent = EventTable::getByPrimary((int)$mainEvent['ID'])->fetch();
			if (!$currentMainEvent || !self::isOccupancyRowRestored($mainEvent, $currentMainEvent))
			{
				return false;
			}
		}
		elseif ($currentMainEventId > 0 && EventTable::getByPrimary($currentMainEventId)->fetch())
		{
			return false;
		}

		foreach ($snapshot['roomEvents'] ?? [] as $roomEvent)
		{
			$currentRoomEvent = EventTable::getByPrimary((int)$roomEvent['ID'])->fetch();
			if (!$currentRoomEvent || !self::isOccupancyRowRestored($roomEvent, $currentRoomEvent))
			{
				return false;
			}
		}

		if ($currentMainEventId > 0)
		{
			$currentMainEvent = EventTable::getByPrimary($currentMainEventId)->fetch();
			$currentRoomEventIds = array_column(
				EventTable::query()
					->setSelect(['ID'])
					->where('PARENT_ID', $currentMainEventId)
					->where('CAL_TYPE', self::TYPE)
					->exec()
					->fetchAll(),
				'ID',
			);
			if (is_array($currentMainEvent))
			{
				$currentRoomEventId = (int)(Util::parseLocation($currentMainEvent['LOCATION'] ?? null)['room_event_id'] ?? 0);
				if ($currentRoomEventId > 0)
				{
					$currentRoomEventIds[] = $currentRoomEventId;
				}
			}
			$expectedRoomEventIds = array_keys($snapshot['roomEvents'] ?? []);
			$currentRoomEventIds = array_values(array_unique($currentRoomEventIds));
			sort($currentRoomEventIds, SORT_NUMERIC);
			sort($expectedRoomEventIds, SORT_NUMERIC);
			if (array_map('intval', $currentRoomEventIds) !== array_map('intval', $expectedRoomEventIds))
			{
				return false;
			}
		}

		return true;
	}

	private static function isOccupancyRowRestored(array $expected, array $actual): bool
	{
		foreach (['PARENT_ID', 'CAL_TYPE', 'LOCATION', 'DATE_FROM', 'DATE_TO', 'RRULE', 'EXDATE', 'MEETING'] as $field)
		{
			$expectedValue = $expected[$field] ?? null;
			$actualValue = $actual[$field] ?? null;
			if (
				is_object($expectedValue)
				&& is_object($actualValue)
				&& method_exists($expectedValue, 'getTimestamp')
				&& method_exists($actualValue, 'getTimestamp')
			)
			{
				$isSameValue = $expectedValue->getTimestamp() === $actualValue->getTimestamp();
			}
			else
			{
				$isSameValue = $expectedValue === $actualValue;
			}

			if (!$isSameValue)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Preparing data with rooms and sections for ajax-actions
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function prepareResponseData(): array
	{
		$result = [];

		$result['rooms'] = self::getRoomsList();
		$sectionList = \CCalendar::GetSectionList([
			'CAL_TYPE' => self::TYPE,
			'OWNER_ID' => 0,
			'checkPermissions' => true,
			'getPermissions' => true,
			'getImages' => true
		]);
		$sectionList = array_merge(
			$sectionList,
			\CCalendar::getSectionListAvailableForUser(\CCalendar::GetUserId())
		);
		$result['sections'] = $sectionList;

		return $result;
	}

	/**
	 * @return array|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function prepareRoomManagerData(): ?array
	{
		$userId = \CCalendar::GetUserId();
		$result = [];

		// here we collects permissions cache for rooms @see \CCalendarSect::HandlePermission,
		// if it not collected here and further @see \CCalendar::GetSectionList called with getPermissions => false,
		// then permissions will not be correct (would be just empty)
		$result['rooms'] = self::getRoomsList();

		$followedSectionList = UserSettings::getFollowedSectionIdList($userId);
		$sectionList = \CCalendar::GetSectionList([
			'CAL_TYPE' => self::TYPE,
			'OWNER_ID' => 0,
			'ADDITIONAL_IDS' => $followedSectionList,
			'getPermissions' => false,
		]);
		$sectionList = array_merge($sectionList, \CCalendar::getSectionListAvailableForUser($userId));

		$sectionAccessTasks = \CCalendar::GetAccessTasks('calendar_section', 'location');
		$hiddenSections = UserSettings::getHiddenSections(
			$userId,
			[
				'type' => self::TYPE,
				'ownerId' => 0,
			]
		);
		$defaultSectionAccess = \CCalendarSect::GetDefaultAccess(
			self::TYPE,
			$userId
		);

		$result['sections'] = $sectionList;
		$result['config'] = [
			'locationAccess' => Util::getLocationAccess($userId),
			'hiddenSections' => $hiddenSections,
			'type' => self::TYPE,
			'ownerId' => 0,
			'userId' => $userId,
			'defaultSectionAccess' => $defaultSectionAccess,
			'sectionAccessTasks' => $sectionAccessTasks,
			'showTasks' => false,
			'accessNames' => \CCalendar::GetAccessNames(),
		];

		return $result;
	}

	/**
	 * @param $handler
	 *
	 * @return Manager
	 */
	public function eventHandler($handler): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		foreach(EventManager::getInstance()->findEventHandlers('calendar', $handler) as $event)
		{
			ExecuteModuleEventEx($event, [
				$this->room->getId(),
			]);
		}

		return $this;
	}

	public function addPullEvent(PushCommand $event): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		\Bitrix\Calendar\Util::addPullEvent(
			$event,
			$this->room->getCreatedBy(),
			[
				'ID' => $this->room->getId()
			]
		);

		return $this;
	}

	/**
	 * @param int $id
	 *
	 * @return string|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getRoomName(int $id): ?string
	{
		$section = SectionTable::query()
			->setSelect(['NAME'])
			->where('ID', $id)
			->exec()->fetch()
		;

		return $section ? $section['NAME'] : null;
	}

	/**
	 * @param $name
	 * Validation for name of room
	 *
	 * @return string|null
	 */
	public static function checkRoomName(?string $name): ?string
	{
		if (!$name)
		{
			return '';
		}

		$name = trim($name);

		if (empty($name))
		{
			return '';
		}

		return $name;
	}

	/**
	 * Delete location value when deleting room
	 *
	 * @return $this
	 */
	public function deleteLocationFromEvents(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		$guestsId = [];
		$eventsId = [];
		$id = $this->room->getId();
		$locationName = $this->room->getName();
		$locationId = 'calendar_' . $id;

		$events = EventTable::query()
			->setSelect(['ID', 'PARENT_ID', 'OWNER_ID', 'CREATED_BY', 'LOCATION'])
			->whereLike('LOCATION', $locationId. '%')
			->exec()
		;

		while ($event = $events->fetch())
		{
			if ($event['ID'] === $event['PARENT_ID'])
			{
				$guestsId[] = $event['OWNER_ID'];
			}

			$eventsId[] = $event['ID'];
		}

		if (!empty($eventsId))
		{
			EventTable::updateMulti($eventsId, ['LOCATION' => '']);

			$guestsId = array_unique($guestsId);
			$userId = \CCalendar::GetCurUserId();

			foreach ($guestsId as $guestId)
			{
				\CCalendarNotify::Send([
					'mode' => 'delete_location',
					'location' => $locationName,
					'locationId' => $id,
					'guestId' => (int)$guestId,
					'userId' => $userId,
				]);
			}
		}

		return $this;
	}

	/**
	 * @return $this
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function pullDeleteEvents(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		$events = self::getLocationEventsId($this->room->getId());

		foreach ($events as $event)
		{
			if ($this->room->getCreatedBy())
			{
				\Bitrix\Calendar\Util::addPullEvent(
					PushCommand::DeleteEvent,
					$this->room->getCreatedBy(),
					['fields' => $event]
				);
			}
		}

		return $this;
	}

	/**
	 * @return Manager
	 */
	public function deleteEmptyEvents(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		\CCalendarEvent::DeleteEmpty($this->room->getId());

		return $this;
	}

	/**
	 * @param int $roomId
	 *
	 * @return array of location events id with a given id
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function getLocationEventsId(int $roomId): array
	{
		return EventTable::query()
			->setSelect([
	            'ID',
	            'CREATED_BY',
	            'PARENT_ID',
			])
			->where('SECTION_ID', $roomId)
			->where('DELETED', 'N')
			->exec()->fetchAll()
		;
	}

	/**
	 * @return Manager
	 */
	public function saveAccess(): Manager
	{
		if ($this->getError())
		{
			return $this;
		}

		$access = $this->room->getAccess();
		$id = $this->room->getId();

		if (!empty($access))
		{
			\CCalendarSect::SavePermissions(
				$id,
				$access
			);
		}
		else
		{
			\CCalendarSect::SavePermissions(
				$id,
				\CCalendarSect::GetDefaultAccess(
					$this->room->getType(),
					$this->room->getCreatedBy()
				)
			);
		}

		return $this;
	}

	/**
	 * @param Query\Result $query
	 *
	 * @return array
	 */
	private static function prepareRoomsQueryData(Query\Result $query): array
	{
		$roomsId = [];
		$result = [];

		while ($room = $query->fetch())
		{
			$room['ID'] = $room['SECTION_ID'];
			unset($room['SECTION_ID']);

			if (!empty($room['NAME']))
			{
				$room['NAME'] = Emoji::decode($room['NAME']);
			}
			$roomId = (int)$room['ID'];
			$roomsId[] = $roomId;
			$result[$roomId] = $room;
		}

		return [$roomsId, $result];
	}

	/**
	 * @param array $roomsId
	 * @param array $rooms
	 *
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function getRoomsAccess(array $roomsId, array $rooms): array
	{
		if (empty($roomsId))
		{
			return [];
		}

		foreach (array_chunk($roomsId, self::ROOM_QUERY_CHUNK_SIZE) as $roomIdChunk)
		{
			$accessQuery = AccessTable::query()
				->setSelect([
					'ACCESS_CODE',
					'TASK_ID',
					'SECT_ID',
				])
				->whereIn('SECT_ID', $roomIdChunk)
				->exec()
			;

			while ($access = $accessQuery->fetch())
			{
				if (!isset($rooms[$access['SECT_ID']]['ACCESS']))
				{
					$rooms[$access['SECT_ID']]['ACCESS'] = [];
				}
				$rooms[$access['SECT_ID']]['ACCESS'][$access['ACCESS_CODE']] = (int)$access['TASK_ID'];
			}
		}

		return $rooms;
	}
}
