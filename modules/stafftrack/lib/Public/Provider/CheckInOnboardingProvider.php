<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\Date;
use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Internal\Integration\Bizproc\PlanningService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkReportService;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

class CheckInOnboardingProvider
{
	private const NEW_PORTAL_THRESHOLD_DATE = '2026-05-28';
	private const NEW_PORTAL_ONBOARDING_DELAY_SECONDS = 3 * 7 * 86400;

	private const MAX_SHOWS = 4;
	private const SHOW_INTERVAL_SECONDS = 7 * 86400;

	private const ONBOARDING_OPTION_MODULE = 'stafftrack';
	private const ONBOARDING_NODE_OPTION_PREFIX = 'check_in_onboarding_node_';

	public function getShouldShow(int $userId): bool
	{
		if (!Loader::includeModule('stafftrackmobile'))
		{
			return false;
		}

		if (!(new CheckInFeature())->isEnabled())
		{
			return false;
		}

		if ($this->isEnabledByUser($userId))
		{
			return false;
		}

		if (!DepartmentProvider::isHeadOrDeputyOfDepartment($userId))
		{
			return false;
		}

		if ($this->isAiDayPlannerRequiredButMissing())
		{
			return false;
		}

		if ($this->isEnabledForDepartments($userId))
		{
			return false;
		}

		if ($this->getShownCount($userId) >= self::MAX_SHOWS)
		{
			return false;
		}

		return (time() - $this->getLastShownAt($userId)) >= self::SHOW_INTERVAL_SECONDS;
	}

	public function registerShown(int $userId): void
	{
		$optionService = OptionService::getInstance();
		$optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_BANNER_COUNT,
			(string)($this->getShownCount($userId) + 1),
		);
		$optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_BANNER_LAST_SHOWN_AT,
			(string)time(),
		);

		OptionProvider::getInstance()->invalidateCache($userId);
	}

	public function enableAll(): void
	{
		(new CheckInSettingsProvider())->enableAutoCheckIn();

		WorkDayService::enableWorkShiftOption();
		WorkDayService::enableDayStartCheckIn();

		if (WorkReportService::isPlanningServiceEnabled())
		{
			WorkReportService::enableWorkReportOption();
		}

		$userId = (int)CurrentUser::get()->getId();
		$this->enableByUser($userId);
		$this->enableForDepartments($userId);
	}

	private function isEnabledByUser(int $userId): bool
	{
		return $this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_ENABLED) === 'Y';
	}

	private function enableByUser(int $userId): void
	{
		OptionService::getInstance()->save($userId, Option::CHECKIN_ONBOARDING_ENABLED, 'Y');
		OptionProvider::getInstance()->invalidateCache($userId);
	}

	private function isEnabledForDepartments(int $userId): bool
	{
		$nodeIds = DepartmentProvider::getManagedDepartmentIds($userId);
		if ($nodeIds === [])
		{
			return false;
		}

		foreach ($nodeIds as $nodeId)
		{
			if (\Bitrix\Main\Config\Option::get(self::ONBOARDING_OPTION_MODULE, self::ONBOARDING_NODE_OPTION_PREFIX . $nodeId, 'N') !== 'Y')
			{
				return false;
			}
		}

		return true;
	}

	private function enableForDepartments(int $userId): void
	{
		$nodeIds = DepartmentProvider::getManagedDepartmentIds($userId);
		if ($nodeIds === [])
		{
			return;
		}

		foreach ($nodeIds as $nodeId)
		{
			$key = self::ONBOARDING_NODE_OPTION_PREFIX . $nodeId;
			if (\Bitrix\Main\Config\Option::get(self::ONBOARDING_OPTION_MODULE, $key, 'N') !== 'Y')
			{
				\Bitrix\Main\Config\Option::set(self::ONBOARDING_OPTION_MODULE, $key, 'Y');
			}
		}
	}

	private function getShownCount(int $userId): int
	{
		return (int)($this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_BANNER_COUNT) ?? 0);
	}

	private function getLastShownAt(int $userId): int
	{
		return (int)($this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_BANNER_LAST_SHOWN_AT) ?? 0);
	}

	private function getOptionValue(int $userId, Option $option): ?string
	{
		return OptionProvider::getInstance()->getOption($userId, $option)?->getValue();
	}

	public function isPlanningServiceEnabled(): bool
	{
		return WorkReportService::isPlanningServiceEnabled();
	}

	public function canShowMobileOnboardingFor(int $userId): bool
	{
		if (!DepartmentProvider::isHeadOrDeputyOfDepartment($userId))
		{
			return false;
		}

		if ($this->isAiDayPlannerRequiredButMissing())
		{
			return false;
		}

		if ($this->isEnabledByUser($userId) || $this->isEnabledForDepartments($userId))
		{
			return false;
		}

		return !$this->isWithinNewPortalDelay();
	}

	private function isAiDayPlannerRequiredButMissing(): bool
	{
		return PlanningService::isAiAgentRegionAvailable()
			&& !PlanningService::existsSystemDayAgent();
	}

	public function isWithinNewPortalDelay(): bool
	{
		$portalCreateTimestamp = $this->getPortalCreateTimestamp();
		if ($portalCreateTimestamp === null)
		{
			return false;
		}

		$newPortalThreshold = (new Date(self::NEW_PORTAL_THRESHOLD_DATE, 'Y-m-d'))->getTimestamp();
		if ($portalCreateTimestamp < $newPortalThreshold)
		{
			return false;
		}

		return time() < $portalCreateTimestamp + self::NEW_PORTAL_ONBOARDING_DELAY_SECONDS;
	}

	private function getPortalCreateTimestamp(): ?int
	{
		if (Loader::includeModule('bitrix24'))
		{
			$timestamp = (int)\CBitrix24::getCreateTime();
			if ($timestamp > 0)
			{
				return $timestamp;
			}
		}

		$admin = UserTable::getList([
			'select' => ['DATE_REGISTER'],
			'filter' => ['=ID' => 1],
		])->fetchObject();

		return $admin?->getDateRegister()?->getTimestamp();
	}
}
