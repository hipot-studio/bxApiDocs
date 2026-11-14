<?php
namespace Bitrix\Calendar\Controller;

use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\ICal\IcsManager;
use Bitrix\Calendar\ICal\MailInvitation\AttachmentEditManager;
use Bitrix\Calendar\ICal\MailInvitation\Helper;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkRequisite;
use Bitrix\Calendar\Public;
use Bitrix\Calendar\Core\Mappers;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Engine\ActionFilter;

Loc::loadMessages(__FILE__);

class PublicEvent extends \Bitrix\Main\Engine\Controller
{
	private const GUEST_LINK_LOG_CHANNEL = 'public-ics';

	protected ?Event $event;

	public function configureActions(): array
	{
		return [
			'handleDecision' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
					ActionFilter\Csrf::class
				],
				'+postfilters' => [
					new ActionFilter\Cors()
				],
			],
			'getIcsFileContent' => [
				'-prefilters' => [
					ActionFilter\Authentication::class,
					ActionFilter\Csrf::class
				],
				'+postfilters' => [
					new ActionFilter\Cors()
				],
			],
		];
	}

	public function handleDecisionAction(int $eventId, string $hash, string $decision): string
	{
		$this->event = (new Mappers\Event())->getById($eventId);

		if ($this->event === null || !Public\PublicEvent::isHashValid($this->event, $hash))
		{
			$this->addError(new Error('Event not found'));

			return '';
		}

		$status = $decision === 'Y' ? 'Y' : 'N';
		if ($this->event->getMeetingStatus() === $decision)
		{
			return $status;
		}

		\CCalendarEvent::SetMeetingStatus(
			[
				'userId' => $this->event->getOwner()->getId(),
				'eventId' => $this->event->getId(),
				'status' => $status,
				'personalNotification' => true,
			],
		);

		return $status;
	}

	public function getIcsFileContentAction(int $eventId, string $hash): string
	{
		$this->event = (new Mappers\Event())->getById($eventId);
		if (!$this->event || !Public\PublicEvent::isHashValid($this->event, $hash))
		{
			$this->addError(new Error('Event not found'));

			return '';
		}

		$detailLink = Public\PublicEvent::getDetailLinkFromEvent($this->event);
		if ($detailLink === null)
		{
			$this->addError(new Error('Event doesnt have owner or date'));

			return '';
		}

		if ($this->event->getMeetingDescription()?->getHideGuests())
		{
			$this->event->setAttendeesCollection(null);
		}

		$descriptionParams = [
			'eventUrl' => $detailLink,
		];

		// Read-only by design: this action drops the Authentication and Csrf prefilters, so it
		// is reachable anonymously by a valid hash and must not create the meeting chat or
		// mint a new link. Normalizing an attendee copy to the parent event, where the chat
		// lives, is the resolver's job.
		$guestLinkUrl = (new GuestLinkRequisite(self::GUEST_LINK_LOG_CHANNEL))->resolveExistingUrl(
			(int)$this->event->getId(),
			$this->resolveIcsOrganizerId(),
		);

		if ($guestLinkUrl !== null)
		{
			// Without this a legacy call_sync event would carry the link twice: as the new
			// requisite and as the legacy line still sitting inside its description.
			GuestLinkRequisite::stripLegacyLink($this->event);
			$descriptionParams['guestLinkUrl'] = $guestLinkUrl;
		}

		$attachmentManager = new AttachmentEditManager($this->getEventArrayForIcs($descriptionParams));
		if (!$attachmentManager->getUid())
		{
			return IcsManager::getInstance()->getIcsFileContent($this->event, $descriptionParams);
		}

		return $attachmentManager->getContent();
	}

	/**
	 * User the guest link is resolved on behalf of. There is no current user in this flow, so
	 * the same trusted principal as in GuestLinkResolver is used.
	 */
	private function resolveIcsOrganizerId(): int
	{
		if ($this->event->getCalendarType() === Dictionary::CALENDAR_TYPE['user'])
		{
			$sectionOwnerId = (int)($this->event->getSection()->getOwner()?->getId() ?? 0);
			if ($sectionOwnerId > 0)
			{
				return $sectionOwnerId;
			}
		}

		return (int)($this->event->getCreator()?->getId() ?? 0);
	}

	private function getEventArrayForIcs(array $descriptionParams): array
	{
		$parentId = $this->event->getParentId();
		$eventArray = (new Mappers\Event())->convertToArray($this->event);
		$description = IcsManager::getInstance()->prepareEventDescription($this->event, $descriptionParams);
		$eventArray['DESCRIPTION'] = str_replace("\\n", "\n", $description);
		$eventArray['ICAL_ORGANIZER'] = Helper::getAttendee($eventArray['MEETING_HOST'], $parentId, false);
		if (!$this->event->getMeetingDescription()?->getHideGuests())
		{
			$eventArray['ICAL_ATTENDEES'] = Helper::getAttendeesByEventParentId($parentId);
		}

		$eventArray['ICAL_ATTACHES'] = Helper::getMailAttaches(null, $eventArray['MEETING_HOST'], $parentId);

		return $eventArray;
	}
}
