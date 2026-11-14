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
		return Option::get('mobile', self::OPTION_CODE, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'Y');
	}

	public function disable(): void
	{
		Option::set('mobile', self::OPTION_CODE, 'N');
	}
}
