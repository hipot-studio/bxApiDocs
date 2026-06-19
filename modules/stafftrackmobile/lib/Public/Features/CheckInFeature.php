<?php

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class CheckInFeature extends FeatureFlag
{
	private const OPTION_NAME = 'new_check_in_enabled';

	public function isEnabled(): bool
	{
		return $this->isOptionEnabled();
	}

	public function enable(): void
	{
		Option::set('stafftrackmobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::delete('stafftrackmobile', ['name' => self::OPTION_NAME]);
	}

	private function isOptionEnabled(): bool
	{
		return Option::get('stafftrackmobile', self::OPTION_NAME, 'N') === 'Y';
	}
}
