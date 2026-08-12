<?php

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\PlanningService;
use Bitrix\Timeman\V2\Internal\Integration\HumanResources\Service\NodeSettingsService;

class SettingsProvider
{
	private int $userId;
	private NodeSettingsService $nodeSettingsService;

	public function __construct(int $userId = 0)
	{
		$this->userId = $userId ?: (int)CurrentUser::get()->getId();
		$this->nodeSettingsService = new NodeSettingsService();
	}

	public function isShiftsEnabled(): bool
	{
		return $this->nodeSettingsService->isShiftsEnabled($this->userId);
	}

	public function isShiftsEnabledInSettings(): bool
	{
		return $this->nodeSettingsService->isShiftsEnabledInSettings($this->userId);
	}

	public function canChangeShiftsSetting(): bool
	{
		return $this->nodeSettingsService->canChangeShiftsSetting($this->userId);
	}

	public function enableShifts(): void
	{
		$this->nodeSettingsService->enableShifts($this->userId);
	}

	public function disableShifts(): void
	{
		$this->nodeSettingsService->disableShifts($this->userId);
	}

	public function isReportsEnabled(): bool
	{
		return $this->nodeSettingsService->isReportsEnabled($this->userId);
	}

	public function isPlanningServiceEnabled(): bool
	{
		return PlanningService::existsSystemDayAgent();
	}

	public function isReportsEnabledWithAi(): bool
	{
		return $this->isReportsEnabled() && $this->isPlanningServiceEnabled();
	}

	public function hasAiReportAccess(): bool
	{
		return $this->nodeSettingsService->hasAiReportAccess($this->userId);
	}

	public function isReportsEnabledInSettings(): bool
	{
		return $this->nodeSettingsService->isReportsEnabledInSettings($this->userId);
	}

	public function canChangeReportsSetting(): bool
	{
		return $this->nodeSettingsService->canChangeReportsSetting($this->userId);
	}

	public function enableReports(): void
	{
		$this->nodeSettingsService->enableReports($this->userId);
	}

	public function disableReports(): void
	{
		$this->nodeSettingsService->disableReports($this->userId);
	}

	public function isDayStartCheckInEnabled(): bool
	{
		return $this->nodeSettingsService->isDayStartCheckInEnabled($this->userId);
	}

	public function isDayStartCheckInEnabledInSettings(): bool
	{
		return $this->nodeSettingsService->isDayStartCheckInEnabledInSettings($this->userId);
	}

	public function canChangeDayStartCheckIn(): bool
	{
		return $this->nodeSettingsService->canChangeDayStartCheckIn($this->userId);
	}

	public function enableDayStartCheckIn(): void
	{
		$this->nodeSettingsService->enableDayStartCheckIn($this->userId);
	}

	public function disableDayStartCheckIn(): void
	{
		$this->nodeSettingsService->disableDayStartCheckIn($this->userId);
	}
}
