<?php

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Error;
use Bitrix\StaffTrack\Public\Provider\CheckInOnboardingProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\IntranetOnly;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\NewCheckInEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;
use Bitrix\StaffTrack\Feature;

final class Onboarding extends Base
{
	private const STAGE_ONBOARDING = 'onboarding';
	private const STAGE_AHA = 'aha';
	private const STAGE_GEO_AHA = 'geo_aha';

	private const GEO_AHA_STEP_STATES = [
		0 => CheckInOnboardingProvider::GEO_AHA_STATE_PENDING,
		1 => CheckInOnboardingProvider::GEO_AHA_STATE_SHOWN,
	];

	private const ALLOWED_STAGES = [
		self::STAGE_ONBOARDING,
		self::STAGE_AHA,
		self::STAGE_GEO_AHA,
	];

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
	 * Returns the mobile onboarding tour configuration.
	 *
	 * `isPlanningServiceEnabled` and `isWorkReportsEnabled` are always false: the AI reports slides are hidden
	 * regardless of the planning service.
	 * `canEnableFeatures` is false when there is nothing left to enable, which turns the tour into preview mode.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Onboarding.getConfig
	 * @return array{type: string, isPlanningServiceEnabled: bool, isWorkReportsEnabled: bool, canEnableFeatures: bool, employeeAhaResumeIndex: int, employeeGeoAhaState: string|null, isGeoFeaturesAvailableByRegion: bool}
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function getConfigAction(): array
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$settingsProvider = new CheckInSettingsProvider();
		$isManager = DepartmentProvider::isHeadOrDeputyOfDepartment($userId);
		$onboardingProvider = new CheckInOnboardingProvider();

		return [
			'type' => $isManager ? 'manager' : 'employee',
			'isPlanningServiceEnabled' => false,
			'isWorkReportsEnabled' => false,
			'canEnableFeatures' => $isManager
				&& $settingsProvider->isTimemanAvailable()
				&& $settingsProvider->isWorkTimeToolAvailable()
				&& !$onboardingProvider->isCheckInEnabledForHierarchy($userId),
			'employeeAhaResumeIndex' => $onboardingProvider->getEmployeeAhaResumeIndex($userId),
			'employeeGeoAhaState' => $onboardingProvider->getEmployeeGeoAhaState($userId),
			'isGeoFeaturesAvailableByRegion' => Feature::isGeoFeaturesAvailableByRegion(),
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

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Onboarding.markEmployeeState
	 * @param string $stage
	 * @param int $step
	 * @return array{success: bool}|null
	 */
	#[CloseSession]
	#[IntranetOnly]
	public function markEmployeeStateAction(string $stage, int $step = 0): ?array
	{
		if (!in_array($stage, self::ALLOWED_STAGES, true))
		{
			$this->addError(new Error('Unknown stage: ' . $stage, 422));

			return null;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		$provider = new CheckInOnboardingProvider();

		if ($stage === self::STAGE_ONBOARDING)
		{
			$provider->markEmployeeOnboardingShown($userId);
		}
		elseif ($stage === self::STAGE_GEO_AHA)
		{
			$state = self::GEO_AHA_STEP_STATES[$step] ?? null;

			if ($state === null)
			{
				$this->addError(new Error('Unknown geo aha step: ' . $step, 422));

				return null;
			}

			$provider->setEmployeeGeoAhaState($userId, $state);
		}
		else
		{
			$provider->setEmployeeAhaResumeIndex($userId, $step);
		}

		return ['success' => true];
	}
}
