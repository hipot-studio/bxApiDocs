<?php

namespace Bitrix\Call\Promotion;

use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Settings;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UI\Tour;

final class SyncTabBannerPromo
{
	// The date is part of the id by convention of the promo registry: bumping it starts the
	// promo over for everyone, because the view marks of the previous run belong to the old id.
	public const ID = 'call:sync-tab-banner:22072026:all';

	/**
	 * Tells whether the current user still has to see the sync tab banner.
	 *
	 * @return bool
	 */
	public static function needToShow(): bool
	{
		// Short-circuit before building the Tour: Tour::isAvailable() writes the portal-wide
		// activation date, and portals without the sync preset must stay untouched.
		if (!Settings::isSyncPresetEnabled())
		{
			return false;
		}

		if (self::getUserId() < 1)
		{
			return false;
		}

		// The banner leads to /sync/, which a guest has no access to; the check sits before
		// Tour so a guest visit does not spend the promo show meant for a real user.
		if ((new CallLogService())->isGuestUser(self::getUserId()))
		{
			return false;
		}

		return self::getTour()->isAvailable(self::getUserId());
	}

	/**
	 * Takes the show for the current user: marks the banner as seen and reports whether
	 * this call is the one that got it.
	 *
	 * @return bool
	 */
	public static function claim(): bool
	{
		if (!self::needToShow())
		{
			return false;
		}

		self::getTour()->setViewDate(self::getUserId());

		return true;
	}

	private static function getTour(): Tour
	{
		$tour = new Tour(self::ID);
		$tour->setUserType(Tour::USER_TYPE_ALL); // default OLD would hide the banner from new users
		$tour->setLifetime(0); // default 30 days would expire the banner, 0 means endless

		return $tour;
	}

	private static function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
