<?php

namespace Bitrix\TimemanMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

final class WorkReportsFeature extends FeatureFlag
{
	private const OPTION_NAME = 'work_reports_enabled';

	public function isEnabled(): bool
	{
		return Loader::includeModule('stafftrack')
			&& Loader::includeModule('stafftrackmobile')
			&& Feature::isEnabled(CheckInFeature::class)
			&& $this->isOptionEnabled();
	}

	public function enable(): void
	{
		Option::set('timemanmobile', self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::delete('timemanmobile', ['name' => self::OPTION_NAME]);
	}

	private function isOptionEnabled(): bool
	{
		return Option::get('timemanmobile', self::OPTION_NAME, 'N') === 'Y';
	}
}
