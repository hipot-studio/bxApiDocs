<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Infrastructure\Controllers\CheckIn;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Public\Provider\CheckInGeoPermissionProvider;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;
use Bitrix\StaffTrack\Public\Provider\OfficePresenceProvider;
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
	 * The background contour of the client reads this state (and caches it for a day) to decide
	 * whether to ask for the location permission and to start tracking, so it carries the regional
	 * availability of geo features as well, and whether the employee may confirm their presence in the
	 * office by a signal - a client without that permission makes no such call at all.
	 *
	 * It also carries the decision whether the popup may still be shown to the employee of the request:
	 * after enough postponements in a row it stops asking. The decision is taken by the permission
	 * facade, so the client knows neither the threshold nor the count, and a server that does not send
	 * the key is read by the client as one with no limit.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.getLocationPermissionPopupState
	 * @return array{isAutoCheckInEnabledInSettings: bool, isCheckInRequiredOnWorkDayStart: bool, isCheckInRolloutEnabled: bool, isGeoFeaturesAvailableByRegion: bool, isPostponementLimitReached: bool, isPresenceSignalEnabled: bool}
	 */
	#[CloseSession]
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function getLocationPermissionPopupStateAction(): array
	{
		$checkInSettingsProvider = new CheckInSettingsProvider();
		$userId = (int)$this->getCurrentUser()?->getId();

		return [
			'isAutoCheckInEnabledInSettings' => $checkInSettingsProvider->isAutoCheckInEnabledInSettings(),
			'isCheckInRequiredOnWorkDayStart' => $checkInSettingsProvider->isDayStartCheckInEnabled(),
			'isCheckInRolloutEnabled' => Feature::isCheckInRolloutEnabled($userId),
			'isGeoFeaturesAvailableByRegion' => $checkInSettingsProvider->isGeoFeaturesAvailableByRegion(),
			'isPostponementLimitReached' => $this->isPostponementLimitReached($userId),
			'isPresenceSignalEnabled' => (new OfficePresenceProvider())->isSignalAllowed($userId),
		];
	}

	/**
	 * Called when the employee leaves the popup without the permission, by the button as well as by
	 * the swipe. The action takes no parameter: which scenarios the mark names is decided by the
	 * server, because the state above is cached by the client for a day.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.postponeLocationPermission
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function postponeLocationPermissionAction(): void
	{
		(new CheckInGeoPermissionProvider())->registerPostponed((int)$this->getCurrentUser()?->getId());
	}

	/**
	 * Called when the permission is actually granted. The client is what tells the server about it:
	 * the consent itself is kept on the device, and a granted permission of the phone leaves no trace
	 * the server could read on its own. Repeating the call changes nothing.
	 *
	 * @ajaxAction stafftrackmobile.v2.CheckIn.Settings.registerLocationPermissionGranted
	 */
	#[IntranetOnly]
	#[NewCheckInEnabled]
	public function registerLocationPermissionGrantedAction(): void
	{
		(new CheckInGeoPermissionProvider())->registerGranted((int)$this->getCurrentUser()?->getId());
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

	/**
	 * stafftrack and stafftrackmobile are updated independently, and the popup state is what the client
	 * picks the generation of the check-in by: an error there hands the session over to v2, which then
	 * reads auto check-ins as disabled for the whole portal. So a stafftrack build without the decision
	 * is read as one with no limit, the same way the client reads a server that does not send the key.
	 */
	private function isPostponementLimitReached(int $userId): bool
	{
		if (!method_exists(CheckInGeoPermissionProvider::class, 'isPostponementLimitReached'))
		{
			return false;
		}

		return (new CheckInGeoPermissionProvider())->isPostponementLimitReached($userId);
	}
}
