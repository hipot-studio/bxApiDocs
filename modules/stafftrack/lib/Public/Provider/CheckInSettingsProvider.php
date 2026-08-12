<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
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

	public function isAutoCheckInEnabledInSettings(): bool
	{
		return $this->nodeSettingsService->isAutoCheckInEnabledInSettings($this->userId);
	}

	public function canChangeAutoCheckIn(): bool
	{
		return $this->nodeSettingsService->canChangeAutoCheckIn($this->userId);
	}

	public function enableAutoCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			$this->nodeSettingsService->enableAutoCheckIn($this->userId);
		}
	}

	public function disableAutoCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			$this->nodeSettingsService->disableAutoCheckIn($this->userId);
		}
	}

	public function isDayStartCheckInEnabled(): bool
	{
		return $this->isCheckInEnabled() && WorkDayService::isDayStartCheckInEnabled();
	}

	public function isDayStartCheckInEnabledInSettings(): bool
	{
		return WorkDayService::isDayStartCheckInEnabledInSettings();
	}

	public function canChangeDayStartCheckIn(): bool
	{
		return WorkDayService::canChangeDayStartCheckIn();
	}

	public function enableDayStartCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			WorkDayService::enableDayStartCheckIn();
		}
	}

	public function disableDayStartCheckIn(): void
	{
		if ($this->isCheckInEnabled())
		{
			WorkDayService::disableDayStartCheckIn();
		}
	}

	public function isWorkTimeToolAvailable(): bool
	{
		return WorkDayService::isWorkTimeToolAvailable();
	}
}
