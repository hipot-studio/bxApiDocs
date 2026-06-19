<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrackMobile\Public\Features\AutoCheckInFeature;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

class Feature extends Base
{
	/**
	 * @ajaxAction stafftrackmobile.v2.Feature.isNewCheckInFeatureEnabled
	 * @return bool
	 */
	#[CloseSession]
	public function isNewCheckInFeatureEnabledAction(): bool
	{
		return (new CheckInFeature())->isEnabled();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.Feature.isAutoCheckInFeatureEnabled
	 * @return bool
	 */
	#[CloseSession]
	public function isAutoCheckInFeatureEnabledAction(): bool
	{
		return (new AutoCheckInFeature())->isEnabled();
	}
}
