<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrack\Feature as StaffTrackFeature;
use Bitrix\StaffTrackMobile\Public\Features\AutoCheckInFeature;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\StaffTrackMobile\Public\Providers\CheckIn\OfficesTabProvider;

class Feature extends Base
{
	/**
	 * @ajaxAction stafftrackmobile.v2.Feature.isNewCheckInFeatureEnabled
	 * @return array{isEnabled: true, isCheckInRolloutEnabled: bool, isOfficesTabEnabled: bool}|false
	 */
	#[CloseSession]
	public function isNewCheckInFeatureEnabledAction(): array|false
	{
		if (!(new CheckInFeature())->isEnabled())
		{
			// stay falsy for bundles cached on devices: they read this response as a boolean
			return false;
		}

		$userId = (int)$this->getCurrentUser()?->getId();

		return [
			'isEnabled' => true,
			'isCheckInRolloutEnabled' => StaffTrackFeature::isCheckInRolloutEnabled($userId),
			// a link to an office opens the offices tab only for those who have it, the web card for the rest
			'isOfficesTabEnabled' => (new OfficesTabProvider())->isAvailable(),
		];
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
