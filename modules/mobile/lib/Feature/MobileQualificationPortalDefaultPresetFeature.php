<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class MobileQualificationPortalDefaultPresetFeature extends FeatureFlag
{
	private const OPTION_NAME = 'mobile_qualification_portal_default_preset_enabled';

	public function isEnabled(): bool
	{
		return Option::get('mobile', self::OPTION_NAME, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_NAME]);
	}
}
