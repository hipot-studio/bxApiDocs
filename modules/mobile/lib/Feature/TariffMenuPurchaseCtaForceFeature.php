<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

/**
 * Debug-only forcing of the tariff purchase call: it lights the call on any platform and in a build
 * of any store, lifting both the server flag and the store rule of the client.
 */
final class TariffMenuPurchaseCtaForceFeature extends FeatureFlag
{
	private const OPTION_CODE = 'feature_tariff_menu_purchase_cta_force_enabled';

	public function isEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
