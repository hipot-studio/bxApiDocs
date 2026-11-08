<?php

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class CheckInV3StaffSelectorFeature extends FeatureFlag
{
	private const OPTION_NAME = 'check_in_v3_staff_selector_enabled';

	public function isEnabled(): bool
	{
		return Option::get('stafftrackmobile', self::OPTION_NAME, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('stafftrackmobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::delete('stafftrackmobile', ['name' => self::OPTION_NAME]);
	}
}
