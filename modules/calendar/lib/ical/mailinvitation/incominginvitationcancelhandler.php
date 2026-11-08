<?php


namespace Bitrix\Calendar\ICal\MailInvitation;


use Bitrix\Calendar\Internal\Event\ExcludedInstanceOperation;
use Bitrix\Calendar\Internal\Event\ExcludedInstanceUserContext;
use Bitrix\Calendar\ICal\Parser\Calendar;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use CCalendar;

class IncomingInvitationCancelHandler extends IncomingInvitationHandler
{
	/**
	 * @var int
	 */
	private $userId;
	/**
	 * @var Calendar
	 */
	private $icalComponent;

	/**
	 * IncomingInvitationCancelHandler constructor.
	 */
	public function __construct()
	{
	}

	/**
	 * @param int $userId
	 * @param Calendar $icalComponent
	 * @return IncomingInvitationCancelHandler
	 */
	public static function createWithComponent(int $userId, Calendar $icalComponent): IncomingInvitationCancelHandler
	{
		$handler = new self();
		$handler->userId = $userId;
		$handler->icalComponent = $icalComponent;

		return $handler;
	}

	/**
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function handle(): bool
	{
		$icalEvent = $this->icalComponent->getEvent();
		$event = Helper::getEventByUId($icalEvent->getUid(), (int)$this->userId);

		if ($event)
		{
			if ($this->isPortalEvent($event))
			{
				return true;
			}

			if ($icalEvent->getRecurrenceId() !== null)
			{
				$date = $this->getExdateFromRecurrenceId($icalEvent->getRecurrenceId());
				if ($date !== null)
				{
					return $this->cancelRecurringInstance(
						(int)$event['ID'],
						(int)$event['OWNER_ID'],
						$date->format(CCalendar::DFormat(false)),
					);
				}
			}
			else
			{
				return CCalendar::DeleteEvent($event['ID'], true, [
					'sendNotification' => true,
					'userId' => (int)$event['OWNER_ID'],
				]);
			}
		}

		return false;
	}

	private function cancelRecurringInstance(int $eventId, int $ownerId, string $excludeDate): bool
	{
		$userId = (int)$this->userId;
		if ($userId <= 0 || $ownerId !== $userId)
		{
			return false;
		}

		$result = (new ExcludedInstanceUserContext())->run(
			$userId,
			static fn () => ExcludedInstanceOperation::execute($eventId, $excludeDate),
		);

		return $result->isSuccess();
	}

	/**
	 * @param mixed $userId
	 * @return IncomingInvitationCancelHandler
	 */
	public function setUserId($userId): IncomingInvitationCancelHandler
	{
		$this->userId = $userId;

		return $this;
	}

	/**
	 * @param \Bitrix\Calendar\ICal\Parser\ParserPropertyType|null $property
	 * @return \Bitrix\Main\Type\Date|\Bitrix\Main\Type\DateTime|null
	 */
	private function getExdateFromRecurrenceId(?\Bitrix\Calendar\ICal\Parser\ParserPropertyType $property)
	{
		try
		{
			if ($property?->getParameterValueByName('value') === 'DATE')
			{
				return Helper::getIcalDate($property?->getValue());
			}

			if ($tz = $property?->getParameterValueByName('tzid'))
			{
				return Helper::getIcalDateTime($property?->getValue(), $tz);
			}
		}
		catch (ObjectException)
		{
			return null;
		}

		return null;
	}
}
