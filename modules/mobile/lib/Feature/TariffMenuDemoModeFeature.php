<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class TariffMenuDemoModeFeature extends FeatureFlag
{
	private const OPTION_CODE = 'feature_tariff_menu_demo_mode_enabled';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::OPTION_CODE, false);
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
