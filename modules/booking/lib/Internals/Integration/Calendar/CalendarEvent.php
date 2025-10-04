<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Calendar;

use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\ResourceLinkedEntityCollection;
use Bitrix\Calendar\Core\Base\Date;
use Bitrix\Calendar\Core\Builders\EventBuilderFromArray;
use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\Properties\AttendeeCollection;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers;
use Bitrix\Calendar\Sharing\SharingEventManager;
use Bitrix\Calendar\Sharing\SharingUser;
use Bitrix\Main\EO_User;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;

class CalendarEvent
{
	private const EVENT_TYPE = 'booking';
	private const USER_TYPE = 'booking_client';

	private Mappers\Event $eventMapper;

	public function __construct(
		Mappers\Event|null $eventMapper = null,
	)
	{
		if ($eventMapper)
		{
			$this->eventMapper = $eventMapper;
		}
		else if ($this->isAvailable())
		{
			$this->eventMapper = new Mappers\Event();
		}
	}

	public function create(
		DatePeriod $datePeriod,
		ResourceLinkedEntityCollection $linkedUserEntities,
		Client $client,
	): int|null
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$owner = $this->getUserFromBookingClient($client);
		if (!$owner)
		{
			return null;
		}

		$event = $this->prepareEvent(
			$datePeriod,
			$linkedUserEntities,
			$owner->getId(),
			[
				'eventName' => Loc::getMessage(
					'BOOKING_INTEGRATION_CALENDAR_EVENT_NAME_TPL',
					['#CLIENT_NAME#' => $owner->getName()]
				),
				'description' => '',
			]
		);
		$event = (new Mappers\Event())->create($event, [
			'sendInvitations' => false,
		]);

		$eventId = $event?->getId();
		if ($eventId)
		{
			$this->setEventAccepted($eventId, $linkedUserEntities);
		}

		return $eventId;
	}

	public function update(
		int $eventId,
		DatePeriod $newDatePeriod,
		ResourceLinkedEntityCollection $linkedUserEntities,
	): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		/** @var Event $event */
		$event = $this->eventMapper->getById($eventId);
		if (!$event)
		{
			return;
		}

		$event->setStart(new Date(DateTime::createFromPhp(
			\DateTime::createFromImmutable($newDatePeriod->getDateFrom())
		)));
		$event->setEnd(new Date(DateTime::createFromPhp(
			\DateTime::createFromImmutable($newDatePeriod->getDateTo())
		)));
		$event->setAttendeesCollection(
			(new AttendeeCollection())->setAttendeesCodes(
				$this->prepareAttendeeCodes($event->getOwner()->getId(), $linkedUserEntities)
			)
		);
		$event = $this->eventMapper->update($event);
		if ($eventId = $event?->getId())
		{
			$this->setEventAccepted($eventId, $linkedUserEntities);
		}
	}

	public function delete(int $eventId): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$event = $this->eventMapper->getById($eventId);
		if (!$event)
		{
			return;
		}

		$this->eventMapper->delete($event);
	}

	private function getUserFromBookingClient(Client $client): EO_User|null
	{
		$clientType = $client->getType();

		switch ($clientType->getModuleId())
		{
			case 'crm':
				$clientData = $client->getData();

				$contact = null;
				if ($clientData['emails'] ?? null)
				{
					$contact = $clientData['emails'][0];
				}
				else if ($clientData['phones'] ?? null)
				{
					$contact = $clientData['phones'][0];
				}
				$userParams = [
					'NAME' => $clientData['name'] ?? null,
					'CONTACT_DATA' => $contact,
				];

				break;
			default:
				return null;
		}

		return $this->findOrCreateUser($this->prepareUserParams($userParams));
	}

	private function prepareUserParams(array $userParams): array
	{
		$preparedParams = [];
		$name = $userParams['NAME'] ?? 'Guest';
		$lastName = $userParams['LAST_NAME'] ?? '';

		if ($userParams['CONTACT_DATA'])
		{
			if (SharingEventManager::isEmailCorrect($userParams['CONTACT_DATA']))
			{
				$preparedParams['PERSONAL_MAILBOX'] = $userParams['CONTACT_DATA'];
			}

			if (SharingEventManager::isPhoneNumberCorrect($userParams['CONTACT_DATA']))
			{
				$preparedParams['PERSONAL_PHONE'] = $userParams['CONTACT_DATA'];
			}
		}

		$preparedParams['NAME'] = $name;
		$preparedParams['LAST_NAME'] = $lastName;

		return $preparedParams;
	}

	private function prepareEvent(
		DatePeriod $datePeriod,
		ResourceLinkedEntityCollection $linkedUserEntities,
		int $ownerId,
		array $params = [],
	): Event
	{
		$meeting = [
			'HOST_NAME' => \CCalendar::GetUserName($ownerId),
			'NOTIFY' => true,
			'REINVITE' => false,
			'ALLOW_INVITE' => true,
			'MEETING_CREATOR' => $ownerId,
			'HIDE_GUESTS' => false,
		];

		$eventData = [
			'NAME' => (string)($params['eventName'] ?? ''),
			'DATE_FROM' => $datePeriod->getDateFrom()->format('d-m-Y H:i'),
			'DATE_TO' => $datePeriod->getDateTo()->format('d-m-Y H:i'),
			'TZ_FROM' => $datePeriod->getDateFrom()->getTimezone()->getName(),
			'TZ_TO' => $datePeriod->getDateTo()->getTimezone()->getName(),
			'SKIP_TIME' => 'N',
			'ACCESSIBILITY' => 'busy',
			'IMPORTANCE' => 'normal',
			'MEETING_HOST' => $ownerId,
			'IS_MEETING' => true,
			'MEETING' => $meeting,
			'DESCRIPTION' => (string)($params['description'] ?? ''),
			'REMIND' => [
				['type' => 'min', 'count' => 15],
				['type' => 'min', 'count' => 60],
			],
		];

		$section = \CCalendarSect::GetSectionForOwner(Dictionary::CALENDAR_TYPE['user'], $ownerId);

		$eventData = [
			...$eventData,
			'OWNER_ID' => $ownerId,
			'SECTIONS' => [$section['sectionId']],
			'ATTENDEES_CODES' => $this->prepareAttendeeCodes($ownerId, $linkedUserEntities),
			'EVENT_TYPE' => self::EVENT_TYPE,
		];

		return (new EventBuilderFromArray($eventData))->build();
	}

	private function prepareAttendeeCodes(int $ownerId, ResourceLinkedEntityCollection $linkedUserEntities): array
	{
		$attendeesCodes = ['U' . $ownerId];

		foreach ($linkedUserEntities as $userEntity)
		{
			$attendeesCodes[] = 'U' . $userEntity->getEntityId();
		}

		return $attendeesCodes;
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('calendar');
	}

	private function findOrCreateUser(array $preparedParams): EO_User|null
	{
		if (
			($preparedParams['PERSONAL_MAILBOX'] ?? null)
			|| ($preparedParams['PERSONAL_PHONE'] ?? null)
		)
		{
			$user = $this->findUser($preparedParams);
		}

		return $user ?? $this->createUser($preparedParams);
	}

	private function findUser(array $userParams): EO_User|null
	{
		$query = UserTable::query();

		if ($userParams['PERSONAL_MAILBOX'] ?? null)
		{
			$query->where('PERSONAL_MAILBOX', $userParams['PERSONAL_MAILBOX']);
		}

		if ($userParams['PERSONAL_PHONE'] ?? null)
		{
			$query->where('PERSONAL_PHONE', $userParams['PERSONAL_PHONE']);
		}

		return $query
			->setSelect(['ID', 'NAME'])
			->where('ACTIVE', 'Y')
			->where('EXTERNAL_AUTH_ID', SharingUser::EXTERNAL_AUTH_ID)
			->where('NAME', $userParams['NAME'])
			->whereLike('LOGIN', self::USER_TYPE . '_%')
			->exec()
			->fetchObject()
		;
	}

	private function createUser(array $userParams): EO_User|null
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$login = self::USER_TYPE . '_'
			. Random::getInt(10000, 99999)
			. Random::getString(8)
		;
		$password = md5($login . '|' . Random::getInt(10000, 99999) . '|' . time());
		$xmlId = self::USER_TYPE
			. '|'
			. md5($login . $password . time() . Random::getString(8))
		;

		$userManager = new \CUser();
		$userId = $userManager->add([
			'NAME' => $userParams['NAME'],
			'LAST_NAME' => $userParams['LAST_NAME'],
			'LOGIN' => $login,
			'PASSWORD' => $password,
			'CONFIRM_PASSWORD' => $password,
			'EXTERNAL_AUTH_ID' => SharingUser::EXTERNAL_AUTH_ID,
			'XML_ID' => $xmlId,
			'ACTIVE' => 'Y',
			'PERSONAL_PHONE' => $userParams['PERSONAL_PHONE'] ?? null,
			'PERSONAL_MAILBOX' => $userParams['PERSONAL_MAILBOX'] ?? null,
		]);

		if (!$userId)
		{
			return null;
		}

		if (Loader::includeModule('socialnetwork'))
		{
			\CSocNetUserPerms::SetPerm($userId, 'message', SONET_RELATIONS_TYPE_NONE);
		}

		return UserTable::getById($userId)->fetchObject();
	}

	private function setEventAccepted(int $eventId, ResourceLinkedEntityCollection $linkedUserEntities): void
	{
		foreach ($linkedUserEntities as $linkedUserEntity)
		{
			\CCalendarEvent::SetMeetingStatus([
				'eventId' => $eventId,
				'userId' => (int)$linkedUserEntity->getEntityId(),
				'status' => Dictionary::MEETING_STATUS['Yes'],
				'sharingAutoAccept' => true,
			]);
		}
	}
}
