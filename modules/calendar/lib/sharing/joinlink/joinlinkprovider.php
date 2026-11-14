<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Sharing\Link\EventLink;
use Bitrix\Calendar\Synchronization\Public\Service\SynchronizationFeature;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

/**
 * Resolves the join link for a shared-event booking.
 *
 * Chooses the source per ALG-01: bookings that already carry a CONFERENCE_ID and
 * portals with the guest-chat gates off keep the legacy videoconference; every
 * other booking gets a guest-chat link. CONFERENCE_ID stays a videoconference
 * alias only; a guest link is never written there.
 */
class JoinLinkProvider
{
	private LegacyConferenceProvider $legacyConferenceProvider;
	private GuestChatProvider $guestChatProvider;
	private EventDescriptionLinkFormatter $descriptionFormatter;

	public function __construct(
		?LegacyConferenceProvider $legacyConferenceProvider = null,
		?GuestChatProvider $guestChatProvider = null,
		?EventDescriptionLinkFormatter $descriptionFormatter = null,
	)
	{
		$this->legacyConferenceProvider = $legacyConferenceProvider ?? new LegacyConferenceProvider();
		$this->guestChatProvider = $guestChatProvider ?? new GuestChatProvider();
		$this->descriptionFormatter = $descriptionFormatter ?? new EventDescriptionLinkFormatter();
	}

	/**
	 * Booking entry point: resolves the join link and, for the guest-chat branch,
	 * reflects it in the event DESCRIPTION. Every side effect is fail-soft, so a
	 * failure never changes the booking result.
	 */
	public function ensureForBooking(EventLink $eventLink): JoinLinkResult
	{
		$result = $this->resolve($eventLink);

		if (
			$result->isSuccess()
			&& $result->getBranch() === JoinLinkResult::BRANCH_GUEST_CHAT
			&& $result->getUrl() !== null
		)
		{
			$this->writeGuestLinkToDescription(
				$eventLink->getEventId(),
				$result->getUrl(),
				(int)$eventLink->getOwnerId(),
			);
		}

		return $result;
	}

	public function resolve(EventLink $eventLink): JoinLinkResult
	{
		if ($this->isGuestChatBranch($eventLink))
		{
			return $this->guestChatProvider->resolve($eventLink);
		}

		return $this->legacyConferenceProvider->resolve($eventLink);
	}

	/**
	 * Owner entry point for the conference chat id. Picks the branch without
	 * creating a conference: the legacy branch returns the videoconference chat
	 * (keeping MANAGER_STARTED analytics), the guest branch returns the guest
	 * chat. A guest-branch failure yields a failed result and never falls back
	 * to legacy, so no CONFERENCE_ID is written for a guest event.
	 */
	public function resolveChatId(EventLink $eventLink): JoinLinkResult
	{
		if ($this->isGuestChatBranch($eventLink))
		{
			return $this->guestChatProvider->resolveChatId($eventLink);
		}

		return $this->legacyConferenceProvider->resolveChatId($eventLink);
	}

	private function isGuestChatBranch(EventLink $eventLink): bool
	{
		if (!empty($eventLink->getConferenceId()))
		{
			return false;
		}

		return !$this->guestChatGatesAreOff($eventLink->getOwnerId());
	}

	protected function guestChatGatesAreOff(?int $organizerId): bool
	{
		if (!Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			return true;
		}

		if (!SynchronizationFeature::isOn())
		{
			return true;
		}

		return !Features::isChatWithGuestsAvailable($organizerId);
	}

	protected function writeGuestLinkToDescription(int $eventId, string $url, int $organizerId): void
	{
		try
		{
			/** @var Factory $mapperFactory */
			$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');

			$event = $mapperFactory->getEvent()->getById($eventId);
			if (!$event)
			{
				return;
			}

			$currentDescription = (string)$event->getDescription();
			$newDescription = $this->descriptionFormatter->format($url, $currentDescription);
			if ($newDescription === $currentDescription)
			{
				return;
			}

			$event->setDescription($newDescription);
			$mapperFactory->getEvent()->update($event, [
				'userId' => $organizerId,
				'overSaving' => true,
				'sendInvitations' => false,
				'sendEditNotification' => false,
			]);
		}
		catch (\Throwable)
		{
			// writing the guest link into the description is a fail-soft booking side effect
		}
	}
}
