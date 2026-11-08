<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkReportService;
use Bitrix\StaffTrack\Provider\OptionProvider;

class CheckInSettingsProvider
{
	private int $userId;
	private NodeSettingsService $nodeSettingsService;

	public function __construct()
	{
		$this->userId = (int)CurrentUser::get()->getId();
		$this->nodeSettingsService = new NodeSettingsService();
	}

	public function getSettings(): array
	{
		return [
			'isCheckInEnabled' => $this->isCheckInEnabled(),
			'isTimemanAvailable' => $this->isTimemanAvailable(),
			'isTimemanIntegrationEnabled' => $this->isTimemanIntegrationEnabled(),
			'isAutoCheckInEnabledInSettings' => $this->isAutoCheckInEnabledInSettings(),
			'canChangeAutoCheckIn' => $this->canChangeAutoCheckIn(),
			'isDayStartCheckInEnabledInSettings' => $this->isDayStartCheckInEnabledInSettings(),
			'canChangeDayStartCheckIn' => $this->canChangeDayStartCheckIn(),
		];
	}

	public function isCheckInEnabled(): bool
	{
		return Feature::isCheckInEnabledBySettings();
	}

	public function isTimemanAvailable(): bool
	{
		return WorkDayService::isAvailable();
	}

	public function isTimemanAllowedByLicense(): bool
	{
		return WorkDayService::isAllowedByLicense();
	}

	public function isTimemanIntegrationEnabled(): bool
	{
		$option = OptionProvider::getInstance()->getOption($this->userId, Option::TIMEMAN_INTEGRATION_ENABLED);

		return ($option?->getValue() ?? 'Y') === 'Y';
	}

	public function isAutoCheckInEnabled(): bool
	{
		return $this->isCheckInEnabled() && $this->nodeSettingsService->isAutoCheckInEnabled($this->userId);
	}

	/**
	 * This is the flag the mobile client reads before it starts polling the GPS, and it caches the
	 * answer for a day. So the regional gate is applied here and not by a prefilter on the action:
	 * a filter added later would not reach the copy already cached on the device.
	 */
	public function isAutoCheckInEnabledInSettings(): bool
	{
		return $this->isGeoFeaturesAvailableByRegion() && $this->isAutoCheckInEnabledInNodeSettings();
	}

	public function isGeoFeaturesAvailableByRegion(): bool
	{
		return Feature::isGeoFeaturesAvailableByRegion();
	}

	protected function isAutoCheckInEnabledInNodeSettings(): bool
	{
		return $this->nodeSettingsService->isAutoCheckInEnabledInSettings($this->userId);
	}

	/**
	 * The answer of isAutoCheckInEnabledInSettings() for an employee other than the one making the request,
	 * by the very rules his own device polls the GPS by - the regional gate included. The form of the
	 * session user stays as it is: the permission popup, the settings modal and the mark of a postponement
	 * stand on it.
	 */
	public function isAutoCheckInEnabledInSettingsFor(int $userId): bool
	{
		return
			$userId > 0
			&& $this->isGeoFeaturesAvailableByRegion()
			&& $this->isAutoCheckInEnabledInNodeSettingsFor($userId)
		;
	}

	protected function isAutoCheckInEnabledInNodeSettingsFor(int $userId): bool
	{
		return $this->nodeSettingsService->isAutoCheckInEnabledInSettings($userId);
	}

	public function canChangeAutoCheckIn(): bool
	{
		return $this->nodeSettingsService->canChangeAutoCheckIn($this->userId);
	}

	/**
	 * Switching the feature on is what the region forbids, so the gate stands on this half only. Off
	 * stays open on purpose: it lowers the geo collection, and a flag stored before the region changed
	 * would otherwise be impossible to clear - the reading side answers false, and the value left in
	 * the settings would come back to life the day the region allows geo again.
	 */
	public function enableAutoCheckIn(): void
	{
		if ($this->isGeoFeaturesAvailableByRegion() && $this->isCheckInEnabled())
		{
			$this->enableAutoCheckInInNodeSettings();
		}
	}

	public function disableAutoCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			$this->disableAutoCheckInInNodeSettings();
		}
	}

	protected function enableAutoCheckInInNodeSettings(): void
	{
		$this->nodeSettingsService->enableAutoCheckIn($this->userId);
	}

	protected function disableAutoCheckInInNodeSettings(): void
	{
		$this->nodeSettingsService->disableAutoCheckIn($this->userId);
	}

	/**
	 * Read by the same background contour and cached for the same day as the auto check-in flag next
	 * to it, and by both generations of the mobile client: it is what opens the location permission
	 * popup where the auto mode is off. So the regional gate is applied to the value here as well -
	 * asking for the permission for a feature the region has switched off is the same request either
	 * way it was reached.
	 */
	public function isDayStartCheckInEnabled(): bool
	{
		return
			$this->isGeoFeaturesAvailableByRegion()
			&& $this->isCheckInEnabled()
			&& $this->isDayStartCheckInEnabledInWorkDaySettings()
		;
	}

	protected function isDayStartCheckInEnabledInWorkDaySettings(): bool
	{
		return WorkDayService::isDayStartCheckInEnabled();
	}

	public function isDayStartCheckInEnabledInSettings(): bool
	{
		return WorkDayService::isDayStartCheckInEnabledInSettings();
	}

	public function canChangeDayStartCheckIn(): bool
	{
		return WorkDayService::canChangeDayStartCheckIn();
	}

	/**
	 * Same rule as for the auto mode next to it: the region gates switching on, not switching off.
	 */
	public function enableDayStartCheckIn(): void
	{
		if ($this->isGeoFeaturesAvailableByRegion() && $this->isCheckInEnabled())
		{
			$this->enableDayStartCheckInInWorkDaySettings();
		}
	}

	public function disableDayStartCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			$this->disableDayStartCheckInInWorkDaySettings();
		}
	}

	protected function enableDayStartCheckInInWorkDaySettings(): void
	{
		WorkDayService::enableDayStartCheckIn();
	}

	protected function disableDayStartCheckInInWorkDaySettings(): void
	{
		WorkDayService::disableDayStartCheckIn();
	}

	public function isWorkTimeToolAvailable(): bool
	{
		return WorkDayService::isWorkTimeToolAvailable();
	}

	/**
	 * Draft state of the work report, not a setting - so it stays out of getSettings(), whose
	 * composition is a contract of its own.
	 */
	public function hasReportReadyForSubmit(): bool
	{
		return WorkReportService::hasReportReadyForSubmit($this->userId);
	}
}
