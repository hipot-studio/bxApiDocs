<?php

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;
use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityService;

final class CheckInFeature extends FeatureFlag
{
	private const OPTION_NAME = 'new_check_in_enabled';

	public function isEnabled(): bool
	{
		return $this->isOptionEnabled() && $this->isBizProcDayPlanAvailable();
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

	private function isBizProcDayPlanAvailable(): bool
	{
		return Loader::includeModule('bizproc')
			&& ($this->canUseCheckInWithoutAgentInRegion() || $this->isBizProcDayPlanAgentEnabled());
	}

	/**
	 * Ensure that agent is not available in the region for enable check-in without bizproc features
	 */
	private function canUseCheckInWithoutAgentInRegion(): bool
	{
		$region = new RegionAvailabilityService();
		return !$region->isAvailable();
	}

	private function isBizProcDayPlanAgentEnabled(): bool
	{
		return Option::get('bizproc', 'bitrix_ai_day_plan_available', 'N') === 'Y';
	}
}
