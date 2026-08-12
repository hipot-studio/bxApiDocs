<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrack\Public\Provider\CheckInOnboardingProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\IntranetOnly;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\NewCheckInEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

final class Onboarding extends Base
{
	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Onboarding.shouldShow
	 * @return bool
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function shouldShowAction(): bool
	{
		if (!(new CheckInSettingsProvider())->isTimemanAllowedByLicense())
		{
			return false;
		}

		if (!(new CheckInFeature())->isEnabled())
		{
			return false;
		}

		$userId = (int)$this->getCurrentUser()?->getId();

		return (new CheckInOnboardingProvider())->canShowMobileOnboardingFor($userId);
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Onboarding.getConfig
	 * @return array{isPlanningServiceEnabled: bool, canEnableFeatures: bool}
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function getConfigAction(): array
	{
		$settingsProvider = new CheckInSettingsProvider();

		return [
			'isPlanningServiceEnabled' => (new CheckInOnboardingProvider())->isPlanningServiceEnabled(),
			'canEnableFeatures' => $settingsProvider->isTimemanAvailable() && $settingsProvider->isWorkTimeToolAvailable(),
		];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Onboarding.enableFeatures
	 * @return array{isAutoCheckInEnabledInSettings: bool, isCheckInRequiredOnWorkDayStart: bool}|null
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function enableFeaturesAction(): ?array
	{
		$settingsProvider = new CheckInSettingsProvider();
		if (!$settingsProvider->isTimemanAvailable())
		{
			$this->addError(new \Bitrix\Main\Error('Timeman is not available', 'TIMEMAN_UNAVAILABLE'));

			return null;
		}

		if (!$settingsProvider->isWorkTimeToolAvailable())
		{
			$this->addError(new \Bitrix\Main\Error('Work time feature is not available', 'WORK_TIME_UNAVAILABLE'));

			return null;
		}

		$userId = (int)$this->getCurrentUser()?->getId();

		if (!DepartmentProvider::isHeadOrDeputyOfDepartment($userId))
		{
			$this->addError(new \Bitrix\Main\Error('Access denied', 403));

			return null;
		}

		(new CheckInOnboardingProvider())->enableAll();

		$settingsProvider = new CheckInSettingsProvider();

		return [
			'isAutoCheckInEnabledInSettings' => $settingsProvider->isAutoCheckInEnabledInSettings(),
			'isCheckInRequiredOnWorkDayStart' => false,
		];
	}
}
