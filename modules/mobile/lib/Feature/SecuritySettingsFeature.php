<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class SecuritySettingsFeature extends FeatureFlag
{
	private static string $FEATURE_CODE = 'feature_security_settings_enabled';

	public function isEnabled(): bool
	{
		return (bool)Option::get('mobile', self::$FEATURE_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::$FEATURE_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::$FEATURE_CODE]);
	}
}
