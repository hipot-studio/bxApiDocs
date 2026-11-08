<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

/**
 * Accepts pre-computed boolean predicates from the Integration layer
 * and returns the canonical TariffState. Has zero portal dependencies.
 *
 * Priority order: demo → downgraded → free → expired → almostExpired → autopay → active
 */
final class TariffStateResolver
{
	/** Days threshold below which a non-autopay license is considered "almost expired". */
	public const ALMOST_EXPIRED_DAYS_THRESHOLD = 14;

	/**
	 * All parameters are pre-computed by the Integration layer;
	 * this class performs no portal calls of its own.
	 *
	 * @param bool     $isDemo       true when license type is "demo"
	 * @param bool     $isFree       true when license family is "project" (free plan)
	 * @param bool     $isDowngraded true when portal was downgraded to free
	 * @param bool     $isActive     true when the license has not yet expired
	 * @param bool     $isAutopay    true when autopay is enabled
	 * @param int|null $daysLeft     remaining days (null treated as 0)
	 */
	public function resolve(
		bool $isDemo,
		bool $isFree,
		bool $isDowngraded,
		bool $isActive,
		bool $isAutopay,
		?int $daysLeft,
	): TariffState
	{
		if ($isDemo)
		{
			return TariffState::Demo;
		}

		if ($isDowngraded)
		{
			return TariffState::Downgraded;
		}

		if ($isFree)
		{
			return TariffState::Free;
		}

		$daysLeftValue = $daysLeft ?? 0;

		if (!$isActive && $daysLeftValue <= 0)
		{
			return TariffState::Expired;
		}

		if (!$isAutopay && $daysLeftValue > 0 && $daysLeftValue < self::ALMOST_EXPIRED_DAYS_THRESHOLD)
		{
			return TariffState::AlmostExpired;
		}

		if ($isAutopay && $isActive)
		{
			return TariffState::Autopay;
		}

		return TariffState::Active;
	}
}
