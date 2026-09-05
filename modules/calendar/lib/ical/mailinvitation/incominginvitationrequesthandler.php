<?php


namespace Bitrix\Calendar\ICal\MailInvitation;


use Bitrix\Calendar\Core\Event\Tools;
use Bitrix\Calendar\ICal\Basic;
use Bitrix\Calendar\ICal\Parser\Calendar;
use Bitrix\Calendar\ICal\Parser\Dictionary;
use Bitrix\Calendar\ICal\Parser\Event;
use Bitrix\Calendar\ICal\Parser\ParserPropertyType;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Calendar\Util;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use CCalendar;

IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/calendar/classes/general/calendar.php");
IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"].BX_ROOT."/modules/calendar/lib/ical/incomingeventmanager.php");

/**
 * Class IncomingInvitationRequestHandler
 * @package Bitrix\Calendar\ICal\MailInvitation
 */
class IncomingInvitationRequestHandler extends IncomingInvitationHandler
{
	public const MEETING_STATUS_ACCEPTED_CODE = 'accepted';
	public const MEETING_STATUS_QUESTION_CODE = 'question';
	public const MEETING_STATUS_DECLINED_CODE = 'declined';
	public const SAFE_DELETED_YES = 'Y';
	private const ICAL_SEQUENCE_KEY = 'ICAL_SEQUENCE';

	protected string $decision;
	protected Calendar $icalComponent;
	protected int $userId;
	protected ?string $emailTo = null;
	protected ?string $emailFrom = null;
	protected ?array $organizer = null;

	/**
	 * @return IncomingInvitationRequestHandler
	 */
	public static function createInstance(): IncomingInvitationRequestHandler
	{
		return new self();
	}

	/**
	 * @param int $userId
	 * @param Calendar $icalCalendar
	 * @param string $decision
	 * @return IncomingInvitationRequestHandler
	 */
	public static function createWithDecision(int $userId, Calendar $icalCalendar, string $decision): IncomingInvitationRequestHandler
	{
		$handler = new self();
		$handler->decision = $decision;
		$handler->userId = $userId;
		$handler->icalComponent = $icalCalendar;

		return $handler;
	}

	/**
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function handle(): bool
	{
		if (!$this->isUserContextAllowed())
		{
			return false;
		}

		$icalEvent = $this->icalComponent->getEvent();
		$this->organizer = $this->parseOrganizer($icalEvent->getOrganizer());
		if (!$this->normalizeAddressesByOrganizer())
		{
			return false;
		}
		if (!$this->isOrganizerEmailMatchingSender())
		{
			return false;
		}
		$localEvent = Helper::getEventByUId($icalEvent->getUid(), $this->userId, true);
		if ($localEvent === null)
		{
			$preparedEvent = $this->prepareEventToSave($icalEvent);
			$parentId = $this->saveEvent($preparedEvent);
			if ($parentId <= 0)
			{
				return false;
			}

			$childEvent = EventTable::query()
				->setSelect(['ID','PARENT_ID','OWNER_ID'])
				->where('PARENT_ID', $parentId)
				->where('OWNER_ID', $this->userId)
				->exec()->fetch()
			;

			if ((int)$childEvent['ID'] > 0)
			{
				$this->eventId = (int)$childEvent['ID'];
				return true;
			}
		}
		else
		{
			if ($this->isPortalEvent($localEvent))
			{
				$this->eventId = $localEvent['ID'];
				return true;
			}
			if (!$this->isOrganizerMatchingMeetingHost($localEvent))
			{
				return false;
			}
			if ($this->isOutdatedRequest($icalEvent, $localEvent))
			{
				$this->eventId = (int)$localEvent['ID'];

				return true;
			}

			$tempUser = CCalendar::TempUser();
			try
			{
				$preparedEvent = $this->prepareToUpdateEvent($icalEvent, $localEvent);
				if ($this->updateEvent($preparedEvent, $localEvent))
				{
					$this->eventId = $localEvent['ID'];
					return true;
				}
			}
			finally
			{
				if ($tempUser !== false)
				{
					CCalendar::TempUser($tempUser, false);
				}
			}
		}

		return false;
	}

	/**
	 * @param string $decision
	 * @return $this
	 */
	public function setDecision(string $decision): IncomingInvitationRequestHandler
	{
		$this->decision = $decision;

		return $this;
	}

	/**
	 * @param Calendar $component
	 * @return $this
	 */
	public function setIcalComponent(Calendar $component): IncomingInvitationRequestHandler
	{
		$this->icalComponent = $component;

		return $this;
	}

	/**
	 * @param string $emailTo
	 * @return IncomingInvitationRequestHandler
	 */
	public function setEmailTo(string $emailTo): IncomingInvitationRequestHandler
	{
		$this->emailTo = $emailTo;

		return $this;
	}

	public function setEmailFrom(string $emailFrom): IncomingInvitationRequestHandler
	{
		$this->emailFrom = $emailFrom;

		return $this;
	}

	/**
	 * @param int $userId
	 * @return $this
	 */
	public function setUserId(int $userId): IncomingInvitationRequestHandler
	{
		$this->userId = $userId;

		return $this;
	}

	/**
	 * @param Event $icalEvent
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function prepareEventToSave(Event $icalEvent): array
	{
		$event = [];

		if ($icalEvent->getStart() !== null)
		{
			if ($icalEvent->getStart()->getParameterValueByName('tzid') !== null)
			{
				$event['DATE_FROM'] = Helper::getIcalDateTime(
					$icalEvent->getStart()->getValue(),
					$icalEvent->getStart()->getParameterValueByName('tzid')
				)->format(Date::convertFormatToPhp(FORMAT_DATETIME));
				$event['TZ_FROM'] =
					Util::prepareTimezone(
						$icalEvent->getStart()->getParameterValueByName('tzid')
					)->getName();
			}
			else
			{
				$event['DATE_FROM'] = Helper::getIcalDate($icalEvent->getStart()->getValue())
					->format(Date::convertFormatToPhp(FORMAT_DATE))
				;
				$event['TZ_FROM'] = null;
				$event['SKIP_TIME'] = 'Y';
			}
		}

		if ($icalEvent->getEnd() !== null)
		{
			if ($icalEvent->getEnd()->getParameterValueByName('tzid') !== null)
			{
				$event['DATE_TO'] = Helper::getIcalDateTime(
					$icalEvent->getEnd()->getValue(),
					$icalEvent->getEnd()->getParameterValueByName('tzid')
				)->format(Date::convertFormatToPhp(FORMAT_DATETIME));
				$event['TZ_TO'] = Util::prepareTimezone(
					$icalEvent->getEnd()->getParameterValueByName('tzid')
				)->getName();
			}
			else
			{
				$event['DATE_TO'] = Helper::getIcalDate($icalEvent->getEnd()->getValue())
					->add('-1 days')
					->format(Date::convertFormatToPhp(FORMAT_DATE));
				$event['TZ_TO'] = null;
			}
		}

		if ($icalEvent->getName() !== null)
		{
			$event['NAME'] = !empty($icalEvent->getName()->getValue())
				? $icalEvent->getName()->getValue()
				: Loc::getMessage('EC_DEFAULT_EVENT_NAME_V2')
			;
		}

		if ($icalEvent->getUid() !== null)
		{
			$event['DAV_XML_ID'] = $icalEvent->getUid();
		}

		if ($icalEvent->getModified() !== null)
		{
			$event['TIMESTAMP_X'] = Helper::getIcalDateTime($icalEvent->getModified()->getValue())
				->format(Date::convertFormatToPhp(FORMAT_DATETIME));
		}

		if ($icalEvent->getCreated() !== null)
		{
			$event['DATE_CREATE'] = Helper::getIcalDateTime($icalEvent->getCreated()->getValue())
				->format(Date::convertFormatToPhp(FORMAT_DATETIME));
		}

		if ($icalEvent->getDtStamp() !== null)
		{
			$event['DT_STAMP'] = Helper::getIcalDateTime($icalEvent->getDtStamp()->getValue())
				->format(Date::convertFormatToPhp(FORMAT_DATETIME));
		}

		if ($icalEvent->getRRule() !== null)
		{
			$rrule = $this->parseRRule($icalEvent->getRRule());
			if (isset($rrule['FREQ']) && in_array($rrule['FREQ'], Dictionary::RRULE_FREQUENCY, true))
			{
				$event['RRULE']['FREQ'] = $rrule['FREQ'];

				if (isset($rrule['COUNT']) && (int)$rrule['COUNT'] > 0)
				{
					$event['RRULE']['COUNT'] = $rrule['COUNT'];
				}
				elseif (isset($rrule['UNTIL']))
				{
					$now = Util::getDateObject(null, false)->getTimestamp();
					try
					{
						$until = Helper::getIcalDateTime($rrule['UNTIL']);
					}
					catch (ObjectException $exception)
					{
						// 0181908
						try
						{
							$until = new DateTime($rrule['UNTIL']);
						}
						catch (ObjectException $exception)
						{
							$until = new DateTime(CCalendar::GetMaxDate());
						}
					}

					if ($now < $until->getTimestamp())
					{
						$event['RRULE']['UNTIL'] = $until->format(Date::convertFormatToPhp(FORMAT_DATE));
					}
				}

				if ($rrule['FREQ'] === Dictionary::RRULE_FREQUENCY['weekly'] && isset($rrule['BYDAY']))
				{
					$event['RRULE']['BYDAY'] = $rrule['BYDAY'];
				}

				if (isset($rrule['INTERVAL']))
				{
					$event['RRULE']['INTERVAL'] = $rrule['INTERVAL'];
				}
				else
				{
					$event['RRULE']['INTERVAL'] = 1;
				}
			}
		}

		$event['DESCRIPTION'] = $icalEvent->getDescription() !== null
			? $icalEvent->getDescription()->getValue()
			: ''
		;


		$event['MEETING_HOST'] = Helper::getUserIdByEmail($this->organizer);

		$event['OWNER_ID'] = $this->userId;
		$event['IS_MEETING'] = 1;
		$event['SECTION_CAL_TYPE'] = 'user';
		$event['ATTENDEES_CODES'] = ['U'.$event['OWNER_ID'], 'U'.$event['MEETING_HOST']];

		$event['MEETING_STATUS'] = Tools\Dictionary::MEETING_STATUS['Host'];

		$event['ACCESSIBILITY'] = 'free';
		$event['IMPORTANCE'] = 'normal';
		$event['REMIND'][] = [
			'type' => 'min',
			'count' => '15',
		];
		$event['MEETING'] = [
			'HOST_NAME' => $this->getOrganizerHostName($icalEvent),
			'NOTIFY' => 1,
			'REINVITE' => 0,
			'ALLOW_INVITE' => 0,
			'MEETING_CREATOR' => $event['MEETING_HOST'],
			'EXTERNAL_TYPE' => 'mail',
			self::ICAL_SEQUENCE_KEY => $this->getICalSequence($icalEvent),
		];

		if ($this->decision === 'declined')
		{
			$event['DELETED'] = self::SAFE_DELETED_YES;
		}

		if ($icalEvent->getLocation() !== null)
		{
			$event['LOCATION'] = CCalendar::GetTextLocation($icalEvent->getLocation()->getValue() ?? null);
		}

		return $event;
	}

	/**
	 * @param array $preparedEvent
	 * @return int
	 */
	protected function saveEvent(array $preparedEvent): int
	{
		$preparedEvent['OWNER_ID'] = $preparedEvent['MEETING_HOST'];
		$preparedEvent['MEETING']['MAILTO'] = $this->organizer['EMAIL'] ?? $this->emailTo;
		$preparedEvent['MEETING']['MAIL_FROM'] = $this->emailFrom;

		if ($this->icalComponent->getEvent()->getAttendees())
		{
			$preparedEvent['DESCRIPTION'] .= "\r\n"
				. Loc::getMessage('EC_EDEV_GUESTS') . ": "
				. $this->parseAttendeesForDescription($this->icalComponent->getEvent()->getAttendees());
		}

		if ($this->icalComponent->getEvent()->getAttachments())
		{
			$preparedEvent['DESCRIPTION'] .= "\r\n"
				. Loc::getMessage('EC_FILES_TITLE') . ': '
				. $this->parseAttachmentsForDescription($this->icalComponent->getEvent()->getAttachments());
		}

		$id = (int)CCalendar::SaveEvent([
			'arFields' => $preparedEvent,
			'autoDetectSection' => true,
			'userId' => $this->userId,
			'checkPermission' => !Basic\ICalUtil::isMailUser((int)$preparedEvent['MEETING_HOST']),
		]);
		if ($id <= 0)
		{
			return $id;
		}

		\CCalendarNotify::Send([
			"mode" => 'invite',
			"name" => $preparedEvent['NAME'] ?? null,
			"from" => $preparedEvent['DATE_FROM'] ?? null,
			"to" => $preparedEvent['DATE_TO'] ?? null,
			"location" => CCalendar::GetTextLocation($preparedEvent["LOCATION"] ?? null),
			"guestId" => $this->userId ?? null,
			"eventId" => $id,
			"userId" => $preparedEvent['MEETING_HOST'],
			"fields" => $preparedEvent,
		]);
		\CCalendar::UpdateCounter([$this->userId], [$id]);

		return $id;
	}

	/**
	 * @param array|null $attendeesCollection
	 * @return string
	 */
	protected function parseAttendeesForDescription(?array $attendeesCollection): string
	{
		if (!$attendeesCollection)
		{
			return '';
		}

		$attendees = [];
		foreach ($attendeesCollection as $attendee)
		{
			/**
			 * @var ParserPropertyType $attendee
			 */
			$email = $this->getMailTo($attendee->getValue());
			if (
				!$attendee->getParameterValueByName('cn')
				|| $attendee->getParameterValueByName('cn') === $email
			)
			{
				$attendees[] = $email;
			}
			else
			{
				$attendees[] = $attendee->getParameterValueByName('cn') . " (" . $email . ")";
			}
		}

		return implode(", ", $attendees);
	}

	/**
	 * @param ParserPropertyType|null $organizer
	 * @return array
	 */
	protected function parseOrganizer(?ParserPropertyType $organizer): array
	{
		if (!$organizer)
		{
			return [];
		}

		$result = [];
		$result['EMAIL'] = $this->getMailTo($organizer->getValue());
		$parts = [];
		if (!empty($organizer->getParameterValueByName('cn')))
		{
			$parts = explode(" ", $organizer->getParameterValueByName('cn'), 2);
		}

		if (isset($parts[0]))
		{
			$result['NAME'] = $parts[0];
		}
		if (isset($parts[1]))
		{
			$result['LAST_NAME'] = $parts[1];
		}
		return $result;
	}

	private function isUserContextAllowed(): bool
	{
		$currentUserId = CCalendar::GetCurUserId(true);

		return $currentUserId <= 0 || $currentUserId === $this->userId;
	}

	private function isOrganizerEmailMatchingSender(): bool
	{
		$organizerEmail = $this->normalizeEmail($this->organizer['EMAIL'] ?? null);
		$senderEmail = $this->normalizeEmail($this->emailTo);

		return $organizerEmail !== null && $organizerEmail === $senderEmail;
	}

	private function normalizeAddressesByOrganizer(): bool
	{
		$organizerEmail = $this->normalizeEmail($this->organizer['EMAIL'] ?? null);
		$emailFrom = $this->normalizeEmail($this->emailFrom);
		$emailTo = $this->normalizeEmail($this->emailTo);

		if ($organizerEmail === null)
		{
			return false;
		}

		if ($organizerEmail === $emailTo)
		{
			return true;
		}

		if ($organizerEmail !== $emailFrom)
		{
			return false;
		}

		[$this->emailFrom, $this->emailTo] = [$this->emailTo, $this->emailFrom];

		return true;
	}

	private function normalizeEmail(?string $email): ?string
	{
		$address = new Address($email);

		return $address->validate() ? mb_strtolower($address->getEmail()) : null;
	}

	private function getOrganizerHostName(Event $icalEvent): ?string
	{
		$organizerCn = trim((string)$icalEvent->getOrganizer()?->getParameterValueByName('cn'));
		if ($organizerCn !== '')
		{
			return $organizerCn;
		}

		$senderName = trim((string)(new Address($this->emailTo))->getName());

		return $senderName !== '' ? $senderName : ($this->organizer['EMAIL'] ?? null);
	}

	private function isOrganizerMatchingMeetingHost(array $localEvent): bool
	{
		$meetingHost = Helper::getUserById((int)($localEvent['MEETING_HOST'] ?? 0));
		if ($meetingHost === null)
		{
			return false;
		}

		$organizerEmail = mb_strtolower(trim((string)($this->organizer['EMAIL'] ?? '')));
		$meetingHostEmail = mb_strtolower(trim((string)($meetingHost['EMAIL'] ?? '')));

		return $organizerEmail !== '' && $organizerEmail === $meetingHostEmail;
	}

	private function isOutdatedRequest(Event $icalEvent, array $localEvent): bool
	{
		$meeting = $this->getMeetingData($localEvent);
		if (!array_key_exists(self::ICAL_SEQUENCE_KEY, $meeting))
		{
			return false;
		}

		return $this->getICalSequence($icalEvent) < (int)$meeting[self::ICAL_SEQUENCE_KEY];
	}

	private function getICalSequence(Event $icalEvent): int
	{
		$sequence = $icalEvent->getSequence();
		if ($sequence === null)
		{
			return 0;
		}

		$value = trim((string)$sequence->getValue());

		return $value !== '' && preg_match('/^[0-9]+$/D', $value) === 1 ? (int)$value : 0;
	}

	private function getMeetingData(array $event): array
	{
		if (is_array($event['MEETING'] ?? null))
		{
			return $event['MEETING'];
		}
		$meetingData = (string)($event['MEETING'] ?? '');
		if ($meetingData === '')
		{
			return [];
		}

		$meeting = unserialize($meetingData, ['allowed_classes' => false]);

		return is_array($meeting) ? $meeting : [];
	}

	private function getUpdatedAttendeesCodes(array $localEvent, int $meetingHost): array
	{
		$parentId = (int)($localEvent['PARENT_ID'] ?? 0);
		if ($parentId <= 0)
		{
			$parentId = (int)($localEvent['ID'] ?? 0);
		}
		$attendeesCodes = $localEvent['ATTENDEES_CODES'] ?? '';
		if ($parentId > 0 && $parentId !== (int)($localEvent['ID'] ?? 0))
		{
			$parentEvent = EventTable::query()
				->setSelect(['ATTENDEES_CODES'])
				->where('ID', $parentId)
				->fetch()
			;
			if (is_array($parentEvent))
			{
				$attendeesCodes = $parentEvent['ATTENDEES_CODES'] ?? '';
			}
		}

		if (is_string($attendeesCodes))
		{
			$attendeesCodes = explode(',', $attendeesCodes);
		}
		if (!is_array($attendeesCodes))
		{
			$attendeesCodes = [];
		}

		$attendeesCodes[] = 'U' . $this->userId;
		$attendeesCodes[] = 'U' . $meetingHost;
		$attendeesCodes = array_filter(
			array_map('trim', $attendeesCodes),
			static fn(string $code): bool => $code !== '',
		);

		return array_values(array_unique($attendeesCodes));
	}

	/**
	 * @param Event $icalEvent
	 * @param array $localEvent
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function prepareToUpdateEvent(Event $icalEvent, array $localEvent): array
	{
		$event = [];

		if ($icalEvent->getStart() !== null)
		{
			if ($icalEvent->getStart()->getParameterValueByName('tzid') !== null)
			{
				$event['DATE_FROM'] = Helper::getIcalDateTime(
					$icalEvent->getStart()->getValue(),
					$icalEvent->getStart()->getParameterValueByName('tzid')
				)->format(Date::convertFormatToPhp(FORMAT_DATETIME));
				$event['TZ_FROM'] = Util::prepareTimezone(
					$icalEvent->getStart()->getParameterValueByName('tzid')
				)->getName();
				$event['DT_SKIP_TIME'] = 'N';
				$event['SKIP_TIME'] = false;
			}
			else
			{
				$event['DATE_FROM'] = Helper::getIcalDate($icalEvent->getStart()->getValue())
					->format(Date::convertFormatToPhp(FORMAT_DATE));
				$event['TZ_FROM'] = null;
				$event['DT_SKIP_TIME'] = 'Y';
				$event['SKIP_TIME'] = true;
			}
		}
		else
		{
			$event['DATE_FROM'] = $localEvent['DATE_FROM'];
			$event['TZ_FROM'] = $localEvent['TZ_FROM'];
		}

		if ($icalEvent->getEnd() !== null)
		{
			if ($icalEvent->getEnd()->getParameterValueByName('tzid') !== null)
			{
				$event['DATE_TO'] = Helper::getIcalDateTime(
					$icalEvent->getEnd()->getValue(),
					$icalEvent->getEnd()->getParameterValueByName('tzid')
				)->format(Date::convertFormatToPhp(FORMAT_DATETIME));
				$event['TZ_TO'] = Util::prepareTimezone(
					$icalEvent->getEnd()->getParameterValueByName('tzid')
				)->getName();
			}
			else
			{
				$event['DATE_TO'] = Helper::getIcalDate($icalEvent->getEnd()->getValue())
					->add('-1 days')
					->format(Date::convertFormatToPhp(FORMAT_DATE));
				$event['TZ_TO'] = null;
			}
		}
		else
		{
			$event['DATE_TO'] = $localEvent['DATE_TO'];
			$event['TZ_TO'] = $localEvent['TZ_TO'];
		}

		if ($icalEvent->getName() !== null)
		{
			$event['NAME'] = $icalEvent->getName()->getValue();
		}

		if ($icalEvent->getModified() !== null)
		{
			$event['TIMESTAMP_X'] = Helper::getIcalDateTime($icalEvent->getModified()->getValue())
				->format(Date::convertFormatToPhp(FORMAT_DATETIME));
		}


		if ($icalEvent->getCreated() !== null)
		{
			$invitationDateCreate = Helper::getIcalDateTime($icalEvent->getCreated()->getValue())->getTimestamp();
			$localDateCreate = Util::getDateObject($localEvent['DATE_CREATE'])->getTimestamp();
			if ($invitationDateCreate === $localDateCreate)
			{
				$event['DATE_CREATE'] = Helper::getIcalDateTime($icalEvent->getCreated()->getValue())
					->format(Date::convertFormatToPhp(FORMAT_DATETIME))
				;
			}
		}

		if ($icalEvent->getDtStamp() !== null)
		{
			$event['DT_STAMP'] = Helper::getIcalDateTime($icalEvent->getDtStamp()->getValue())
				->format(Date::convertFormatToPhp(FORMAT_DATETIME));
		}

		$event['DESCRIPTION'] = $icalEvent->getDescription()?->getValue();

		if ($icalEvent->getRRule() !== null)
		{
			$rrule = $this->parseRRule($icalEvent->getRRule());
			if (isset($rrule['FREQ']) && in_array($rrule['FREQ'], Dictionary::RRULE_FREQUENCY, true))
			{
				$event['RRULE']['FREQ'] = $rrule['FREQ'];

				if (isset($rrule['COUNT']) && (int)$rrule['COUNT'] > 0)
				{
					$event['RRULE']['COUNT'] = $rrule['COUNT'];
				}
				elseif (isset($rrule['UNTIL']))
				{
					$now = Util::getDateObject(null, false)->getTimestamp();
					try
					{
						$until = Helper::getIcalDateTime($rrule['UNTIL']);
					}
					catch (ObjectException $exception)
					{
						// 0181908
						try
						{
							$until = new DateTime($rrule['UNTIL']);
						}
						catch (ObjectException $exception)
						{
							$until = new DateTime(CCalendar::GetMaxDate());
						}
					}

					if ($now < $until->getTimestamp())
					{
						$event['RRULE']['UNTIL'] = $until->format(Date::convertFormatToPhp(FORMAT_DATE));
					}
				}

				if ($rrule['FREQ'] === Dictionary::RRULE_FREQUENCY['weekly'] && isset($rrule['BYDAY']))
				{
					$event['RRULE']['BYDAY'] = $rrule['BYDAY'];
				}

				if (isset($rrule['INTERVAL']))
				{
					$event['RRULE']['INTERVAL'] = $rrule['INTERVAL'];
				}
				else
				{
					$event['RRULE']['INTERVAL'] = 1;
				}
			}
		}

		$event['OWNER_ID'] = $this->userId;
		$event['MEETING_HOST'] = (int)$localEvent['MEETING_HOST'];
		$event['IS_MEETING'] = 1;
		$event['SECTION_CAL_TYPE'] = 'user';
		$event['ATTENDEES_CODES'] = $this->getUpdatedAttendeesCodes(
			$localEvent,
			$event['MEETING_HOST'],
		);
		$event['MEETING_STATUS'] = match ($this->decision) {
			self::MEETING_STATUS_ACCEPTED_CODE => Tools\Dictionary::MEETING_STATUS['Yes'],
			self::MEETING_STATUS_DECLINED_CODE => Tools\Dictionary::MEETING_STATUS['No'],
			default => Tools\Dictionary::MEETING_STATUS['Question'],
		};
		$event['ACCESSIBILITY'] = 'free';
		$event['IMPORTANCE'] = 'normal';
		$event['REMIND'] = [
			'type' => 'min',
			'count' => '15',
		];
		$meeting = $this->getMeetingData($localEvent);
		$event['MEETING'] = [
			'HOST_NAME' => $this->getOrganizerHostName($icalEvent),
			'NOTIFY' => $meeting['NOTIFY'] ?? 1,
			'REINVITE' => $meeting['REINVITE'] ?? 0,
			'ALLOW_INVITE' => $meeting['ALLOW_INVITE'] ?? 0,
			'MEETING_CREATOR' => $meeting['MEETING_CREATOR'] ?? $event['MEETING_HOST'],
			'EXTERNAL_TYPE' => 'mail',
			self::ICAL_SEQUENCE_KEY => $this->getICalSequence($icalEvent),
		];
		$event['PARENT_ID'] = $localEvent['PARENT_ID'] ?? null;
		$event['ID'] = $localEvent['ID'] ?? null;
		$event['CAL_TYPE'] = $localEvent['CAL_TYPE'] ?? null;

		if ($this->decision === 'declined')
		{
			$event['DELETED'] = self::SAFE_DELETED_YES;
		}

		if ($icalEvent->getLocation() !== null)
		{
			$event['LOCATION'] = CCalendar::GetTextLocation($icalEvent->getLocation()->getValue() ?? null);
		}

		return $event;
	}

	/**
	 * @param array $updatedEvent
	 * @param array $localEvent
	 * @return bool
	 */
	protected function updateEvent(array $updatedEvent, array $localEvent): bool
	{
		$updatedEvent['ID'] = $localEvent['ID'];
		$updatedEvent['OWNER_ID'] = $this->userId;
		$updatedEvent['MEETING']['MAILTO'] = $this->organizer['EMAIL'] ?? $this->emailTo;
		$updatedEvent['MEETING']['MAIL_FROM'] = $this->emailFrom;

		if ($this->icalComponent->getEvent()->getAttendees())
		{
			$updatedEvent['DESCRIPTION'] .= "\r\n"
				. Loc::getMessage('EC_EDEV_GUESTS') . ": "
				. $this->parseAttendeesForDescription($this->icalComponent->getEvent()->getAttendees());
		}

		if ($this->icalComponent->getEvent()->getAttachments())
		{
			$updatedEvent['DESCRIPTION'] .= "\r\n"
				. Loc::getMessage('EC_FILES_TITLE') . ': '
				. $this->parseAttachmentsForDescription($this->icalComponent->getEvent()->getAttachments());
		}

		$id = (int)\CCalendar::SaveEvent([
			'arFields' => $updatedEvent,
			'userId' => $this->userId,
			'checkPermission' => true,
			'checkCurrentEventPermission' => true,
		]);
		if ($id <= 0)
		{
			return false;
		}

		$entryChanges = \CCalendarEvent::CheckEntryChanges($updatedEvent, $localEvent);

		\CCalendarNotify::Send([
			'mode' => 'change_notify',
			'name' => $updatedEvent['NAME'] ?? null,
			"from" => $updatedEvent['DATE_FROM'] ?? null,
			"to" => $updatedEvent['DATE_TO'] ?? null,
			"location" => CCalendar::GetTextLocation($updatedEvent["LOCATION"] ?? null),
			"guestId" => $this->userId ?? null,
			"eventId" => $updatedEvent['PARENT_ID'] ?? null,
			"userId" => $updatedEvent['MEETING_HOST'],
			"fields" => $updatedEvent,
			"entryChanges" => $entryChanges,
		]);
		\CCalendar::UpdateCounter([$this->userId], [$updatedEvent['ID']]);

		return true;
	}

	protected function parseAttachmentsForDescription(array $icalAttachments): string
	{
		$res = [];
		/** @var ParserPropertyType $attachment */
		foreach ($icalAttachments as $attachment)
		{
			$link = $attachment->getValue();
			if ($name = $attachment->getParameterValueByName('filename'))
			{
				$res[] =  $name . ' (' . $link . ')';
			}
			else
			{
				$res[] = $link;
			}
		}

		return implode(', ', $res);
	}

	private function parseRRule(ParserPropertyType $icalRRule): array
	{
		$result = [];
		$parts = explode(";", $icalRRule->getValue());
		foreach ($parts as $part)
		{
			[$name, $value] = explode("=", $part);
			if ($name === 'BYDAY')
			{
				$value = explode(',', $value);
			}
			$result[$name] = $value;
		}

		return $result;
	}
}
