<?php

namespace Bitrix\Call\Promotion;

use Bitrix\Call\Service\CallLogService;
use Bitrix\Call\Settings;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\UI\Tour;

final class ReactionsOnboardingPromo
{
	// The date is part of the id by convention of the promo registry: bumping it starts the
	// onboarding over for everyone, because the view marks of the previous run belong to the old id.
	public const ID = 'call:reactions-onboarding:29072026:all';

	/**
	 * Tells whether the current user still has to see the reactions onboarding.
	 *
	 * The mark lives on the account rather than on the device, so the hint shown in the web client
	 * is not shown again in the mobile one.
	 *
	 * @return bool
	 */
	public static function needToShow(): bool
	{
		// Short-circuit before building the Tour: Tour::isAvailable() writes the portal-wide
		// activation date, and portals with reactions disabled must stay untouched.
		if (!Settings::isCallReactionsEnabled())
		{
			return false;
		}

		if (self::getUserId() < 1)
		{
			return false;
		}

		// The check sits before Tour so a guest visit does not spend the promo show meant for a
		// real user, nor start its portal-wide lifetime.
		if ((new CallLogService())->isGuestUser(self::getUserId()))
		{
			return false;
		}

		return self::getTour()->isAvailable(self::getUserId());
	}

	/**
	 * Takes the show for the current user: marks the onboarding as seen and reports whether
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
		$tour->setUserType(Tour::USER_TYPE_ALL); // default OLD would hide the onboarding from new users
		$tour->setLifetime(0); // default 30 days would expire the onboarding, 0 means endless

		return $tour;
	}

	private static function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}
}
