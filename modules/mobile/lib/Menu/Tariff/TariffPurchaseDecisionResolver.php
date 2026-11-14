<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

/**
 * Accepts pre-computed predicates of the license from the Integration layer and returns the purchase
 * decision of the tariff plate. Has zero portal dependencies.
 *
 * Priority order: demo → free or downgraded → top tariff or short term → long term
 */
final class TariffPurchaseDecisionResolver
{
	/** Days left at or below which a paid license is offered a renewal instead of an upgrade. */
	public const RENEW_DAYS_THRESHOLD = 60;

	/**
	 * @param bool     $isDemo       true when license type is "demo"
	 * @param bool     $isFree       true when license family is "project" (free plan)
	 * @param bool     $isDowngraded true when portal was downgraded to free
	 * @param bool     $isTopTariff  true when license family is the top one ("ent")
	 * @param int|null $daysLeft     remaining days (null treated as 0)
	 */
	public function resolve(
		bool $isDemo,
		bool $isFree,
		bool $isDowngraded,
		bool $isTopTariff,
		?int $daysLeft,
	): TariffPurchaseDecision
	{
		if ($isDemo)
		{
			return new TariffPurchaseDecision(
				TariffPurchaseAction::Buy,
				TariffTableSelection::FirstCompatible,
			);
		}

		if ($isFree || $isDowngraded)
		{
			return new TariffPurchaseDecision(
				TariffPurchaseAction::Upgrade,
				TariffTableSelection::FirstCompatibleIfRestricted,
			);
		}

		if ($isTopTariff || ($daysLeft ?? 0) <= self::RENEW_DAYS_THRESHOLD)
		{
			return new TariffPurchaseDecision(
				TariffPurchaseAction::Renew,
				TariffTableSelection::CurrentOrFirstCompatible,
			);
		}

		return new TariffPurchaseDecision(
			TariffPurchaseAction::Upgrade,
			TariffTableSelection::NextCompatible,
		);
	}
}
