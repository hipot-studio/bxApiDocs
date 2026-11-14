<?php

namespace Bitrix\CalendarMobile\Integration\Calendar;

use Bitrix\Calendar\Internal\GuestLink\AvailabilityService;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class GuestLinkService
{
	public function __construct(private readonly int $userId)
	{
	}

	/**
	 * Returns the guest invite link for the event, creating the meeting chat if it does
	 * not yet exist. Delegates entirely to calendar's GuestLinkResolver, which owns the
	 * eligible event types.
	 *
	 * @param int $eventId
	 * @return Result data on success: { url: string }
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getOrCreateGuestLink(int $eventId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('calendar'))
		{
			return $result->addError(new Error('Module calendar is not installed'));
		}

		/** @var GuestLinkResolver $resolver */
		$resolver = ServiceLocator::getInstance()->get(GuestLinkResolver::class);

		return $resolver->getOrCreate($eventId, $this->userId);
	}

	/**
	 * Whether the feature is available to the user at all - the per-user pre-gate the mobile
	 * bootstrap carries. Per-event availability is a separate question answered by the
	 * resolver on the saved event.
	 *
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function isAvailableForUser(): bool
	{
		if (!Loader::includeModule('calendar'))
		{
			return false;
		}

		/** @var AvailabilityService $availabilityService */
		$availabilityService = ServiceLocator::getInstance()->get(AvailabilityService::class);

		return $availabilityService->isAvailableForUser($this->userId);
	}
}
