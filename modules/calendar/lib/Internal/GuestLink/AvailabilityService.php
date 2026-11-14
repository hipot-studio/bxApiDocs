<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\GuestLink;

use Bitrix\Calendar\Integration\Bitrix24\FeatureDictionary;
use Bitrix\Calendar\Integration\Bitrix24Manager;
use Bitrix\Calendar\Internal\Integration\Im\GuestLinkService;
use Bitrix\Calendar\Internal\Integration\Intranet\GuestChatService;
use Bitrix\Calendar\Util;

/**
 * Single source of truth for the guest-link feature availability.
 *
 * Availability requires both the calendar email-guests feature flag and the im
 * chat-with-guests feature, with the intranet and im modules included. The guest
 * link is also limited to the employee scope, so external (extranet/collab) users
 * are out of scope regardless of the feature flags. Callers (actions, UI
 * bootstraps) must not duplicate these checks.
 */
class AvailabilityService
{
	protected GuestLinkService $imGuestLinkService;
	protected GuestChatService $intranetGuestChatService;

	/**
	 * External-user verdicts of this hit, keyed by user id. The service is a
	 * ServiceLocator singleton, so a request that asks twice about the same user
	 * (read flow plus UI bootstrap) pays for the extranet lookup once.
	 *
	 * @var array<int, bool>
	 */
	private array $externalUserCache = [];

	public function __construct(
		GuestLinkService $imGuestLinkService,
		GuestChatService $intranetGuestChatService,
	)
	{
		$this->imGuestLinkService = $imGuestLinkService;
		$this->intranetGuestChatService = $intranetGuestChatService;
	}

	/**
	 * Returns true when the guest-link feature is available on the portal.
	 *
	 * @return bool
	 */
	public function isAvailable(?int $userId = null): bool
	{
		if (!$this->intranetGuestChatService->isAvailable())
		{
			return false;
		}

		if (!$this->isCalendarEmailGuestsFeatureEnabled())
		{
			return false;
		}

		return $this->imGuestLinkService->isChatWithGuestsAvailable($userId);
	}

	/**
	 * Returns true when the feature is available AND the user is within the
	 * supported employee scope. Bootstrap/create paths that only have a user id
	 * (no event yet) must use this instead of {@see isAvailable()} so the hint
	 * and the "get link" button are not shown to extranet/collab users, whose
	 * read/create flow is blocked anyway.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isAvailableForUser(int $userId): bool
	{
		return $this->isAvailable($userId) && !$this->isExternalUser($userId);
	}

	/**
	 * Whether the user is an extranet or collab user, i.e. outside the employee
	 * scope the guest link is limited to. Single source of truth for the
	 * external-user guard, reused by {@see GuestLinkResolver}.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isExternalUser(int $userId): bool
	{
		if (!array_key_exists($userId, $this->externalUserCache))
		{
			$this->externalUserCache[$userId] = Util::isExtranetUser($userId) || Util::isCollabUser($userId);
		}

		return $this->externalUserCache[$userId];
	}

	protected function isCalendarEmailGuestsFeatureEnabled(): bool
	{
		return Bitrix24Manager::isFeatureEnabled(FeatureDictionary::CALENDAR_EVENTS_WITH_EMAIL_GUESTS);
	}
}
