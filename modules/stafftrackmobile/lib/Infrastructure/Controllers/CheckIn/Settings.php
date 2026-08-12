<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\IntranetOnly;
use Bitrix\StaffTrackMobile\Infrastructure\ActionFilters\Attribute\NewCheckInEnabled;
use Bitrix\StaffTrackMobile\Infrastructure\Controllers\Base;

final class Settings extends Base
{
	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.getSettings
	 * @return array
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function getSettingsAction(): array
	{
		return (new CheckInSettingsProvider())->getSettings();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.getLocationPermissionPopupState
	 * @return array{isAutoCheckInEnabledInSettings: bool, isCheckInRequiredOnWorkDayStart: bool}
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function getLocationPermissionPopupStateAction(): array
	{
		$checkInSettingsProvider = new CheckInSettingsProvider();

		return [
			'isAutoCheckInEnabledInSettings' => $checkInSettingsProvider->isAutoCheckInEnabledInSettings(),
			'isCheckInRequiredOnWorkDayStart' => $checkInSettingsProvider->isDayStartCheckInEnabled(),
		];
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.enableAutoCheckIn
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function enableAutoCheckInAction(): void
	{
		(new CheckInSettingsProvider())->enableAutoCheckIn();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.disableAutoCheckIn
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function disableAutoCheckInAction(): void
	{
		(new CheckInSettingsProvider())->disableAutoCheckIn();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.enableDayStartCheckIn
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function enableDayStartCheckInAction(): void
	{
		(new CheckInSettingsProvider())->enableDayStartCheckIn();
	}

	/**
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.disableDayStartCheckIn
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function disableDayStartCheckInAction(): void
	{
		(new CheckInSettingsProvider())->disableDayStartCheckIn();
	}
}
