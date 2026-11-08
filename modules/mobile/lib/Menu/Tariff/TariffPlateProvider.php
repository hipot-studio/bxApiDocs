<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

use Bitrix\Intranet\Util;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\TariffMenuDemoModeFeature;
use Bitrix\Mobile\Feature\TariffMenuFeature;
use Bitrix\Mobile\Feature\TariffMenuPurchaseCtaFeature;
use Bitrix\Mobile\Feature\TariffMenuPurchaseCtaForceFeature;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Integration\Market\Subscription;
use Bitrix\Mobile\Menu\Tariff\Dto\DemoPlateData;
use Bitrix\Mobile\Menu\Tariff\Dto\SubscriptionPlateData;
use Bitrix\Mobile\Menu\Tariff\Dto\TariffPlateData;

final class TariffPlateProvider
{
	private License $license;
	private Subscription $subscription;

	public function __construct()
	{
		$this->license = new License();
		$this->subscription = new Subscription();
	}

	/**
	 * Returns whether the tariff plate block should be visible for current user.
	 * Conditions: cloud portal + main feature flag enabled + intranet user + user has buy/manage right.
	 * Also serves as the server-side gate for actions the plate exposes (demo activation).
	 */
	public function isVisible(): bool
	{
		return
			Feature::isEnabled(TariffMenuFeature::class)
			&& Loader::includeModule('intranet')
			&& Util::isIntranetUser()
			&& $this->license->isCloud()
			&& $this->license->currentUserCanManageTariff()
		;
	}

	/**
	 * The QA showcase of the plate states stands in for the tariff card while the demo mode is on.
	 * The answer of the section carries the signal: the map of feature flags the client keeps of its
	 * own is shared by every portal of the device, so a flag read there would follow the user across
	 * portals instead of following the portal.
	 */
	public static function isShowcaseEnabled(): bool
	{
		return Feature::isEnabled(TariffMenuDemoModeFeature::class);
	}

	/**
	 * Returns the full tariff + subscription plate payload for the menu.
	 * When the plate must not be shown, returns ['visible' => false] — the single
	 * source of the wire `visible` flag the client reads.
	 */
	public function getData(): array
	{
		if (!$this->isVisible())
		{
			return ['visible' => false];
		}

		return [
			'visible' => true,
			'tariff' => $this->buildTariffData()->toArray(),
			'subscription' => $this->buildSubscriptionData()->toArray(),
		];
	}

	/**
	 * Returns the narrow tariff plate payload for the menu: the demo plate is the only tariff card
	 * the menu renders while the "My company" section is on, so the block carries its own fields and
	 * no subscription part. The visibility gate stays the same one the full payload passes.
	 */
	public function getDemoPlateData(): array
	{
		if (!$this->isVisible())
		{
			return ['visible' => false];
		}

		return [
			'visible' => true,
			'tariff' => $this->buildDemoPlateData()->toArray(),
		];
	}

	/**
	 * Returns only the subscription part (used after demo activation).
	 */
	public function getSubscriptionData(): ?array
	{
		if (!$this->license->isCloud())
		{
			return null;
		}

		return $this->buildSubscriptionData()->toArray();
	}

	private function buildTariffData(): TariffPlateData
	{
		$state = $this->getTariffState();

		return TariffPlateData::make([
			'name' => $this->license->getName(),
			'type' => $this->license->getType(),
			'family' => $this->license->getFamily(),
			'state' => $state->value,
			'expireText' => $this->formatExpireDate($this->license->getExpireDate()),
			'daysLeft' => $this->license->getDaysLeft(),
			'cta' => $this->resolvePurchaseCta(),
			'ctaForced' => $this->isPurchaseCtaForced(),
			'isDemoAvailable' => $this->license->isDemoAvailable(),
		]);
	}

	private function buildDemoPlateData(): DemoPlateData
	{
		return DemoPlateData::make([
			'state' => $this->getTariffState()->value,
			'isDemoAvailable' => $this->license->isDemoAvailable(),
			'cta' => $this->resolvePurchaseCta(),
			'ctaForced' => $this->isPurchaseCtaForced(),
		]);
	}

	private function buildSubscriptionData(): SubscriptionPlateData
	{
		$subscriptionData = $this->license->getSubscriptionData();
		$isPaid = (bool)($subscriptionData['isPaid'] ?? false);
		$isDemo = (bool)($subscriptionData['isDemo'] ?? false);
		$isDemoUsed = (bool)($subscriptionData['isDemoUsed'] ?? false);
		$isExpired = (bool)($subscriptionData['isExpired'] ?? false);
		$isAlmostExpired = (bool)($subscriptionData['isAlmostExpired'] ?? false);
		$endDate = $this->license->getSubscriptionEndDate();
		$isDemoAvailable = $this->subscription->isDemoAvailable();

		$state = (new SubscriptionStateResolver())->resolve(
			isPaid: $isPaid,
			isDemo: $isDemo,
			isDemoUsed: $isDemoUsed,
			isExpired: $isExpired,
			isAlmostExpired: $isAlmostExpired,
			isDemoAvailable: $isDemoAvailable,
		);

		$expireText = null;
		if ($endDate !== null && ($isPaid || $isDemo))
		{
			$expireText = $this->formatExpireDate($endDate);
		}

		return SubscriptionPlateData::make([
			'state' => $state->value,
			'expireText' => $expireText,
			'isDemoAvailable' => $isDemoAvailable,
			'cta' => $this->resolvePurchaseCta(),
			'ctaForced' => $this->isPurchaseCtaForced(),
			// the right of the portal to buy at all: the region and the model decide it, and a state with
			// nothing running has nothing to offer without it
			'canBuy' => (bool)($subscriptionData['canBuy'] ?? false),
		]);
	}

	/**
	 * Whether the plate may call to purchase: the call follows its own feature flag, and the debug
	 * option lights it alone. The platform decides nothing here - the store rule of the build is
	 * the client's question.
	 */
	private function resolvePurchaseCta(): bool
	{
		return $this->isPurchaseCtaForced() || Feature::isEnabled(TariffMenuPurchaseCtaFeature::class);
	}

	/**
	 * Debug-only: the client lifts the store rule of its build by this mark.
	 */
	private function isPurchaseCtaForced(): bool
	{
		return Feature::isEnabled(TariffMenuPurchaseCtaForceFeature::class);
	}

	/**
	 * The state of the license as the portal really has it. The plate is drawn by the very same one:
	 * `downgraded` is not a card of its own, the client resolves it into the free one.
	 */
	public function getTariffState(): TariffState
	{
		return (new TariffStateResolver())->resolve(
			isDemo: $this->license->isDemo(),
			isFree: $this->license->isFree(),
			isDowngraded: $this->license->isDowngradedToFree(),
			isActive: $this->license->isActive(),
			isAutopay: $this->license->isAutopay(),
			daysLeft: $this->license->getDaysLeft(),
		);
	}

	private function formatExpireDate(?DateTime $date): ?string
	{
		if ($date === null)
		{
			return null;
		}

		$format = Context::getCurrent()->getCulture()->getLongDateFormat();

		return FormatDate($format, $date->getTimestamp());
	}
}
