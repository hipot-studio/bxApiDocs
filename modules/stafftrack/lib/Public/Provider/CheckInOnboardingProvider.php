<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Dictionary\Option;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\Service\NodeSettingsService;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\StaffTrack\Provider\OptionProvider;
use Bitrix\StaffTrack\Service\OptionService;
use Bitrix\StaffTrackMobile\Public\Features\CheckInFeature;

class CheckInOnboardingProvider
{
	public const GEO_AHA_STATE_SHOWN = 'shown';
	public const GEO_AHA_STATE_PENDING = 'pending';

	private const GEO_AHA_STATES = [
		self::GEO_AHA_STATE_SHOWN,
		self::GEO_AHA_STATE_PENDING,
	];

	private const MAX_SHOWS = 4;
	private const SHOW_INTERVAL_SECONDS = 7 * 86400;

	private const ONBOARDING_OPTION_MODULE = 'stafftrack';
	private const ONBOARDING_NODE_OPTION_PREFIX = 'check_in_onboarding_node_';

	private OptionService $optionService;
	private OptionProvider $optionProvider;

	public function __construct(
		?OptionService $optionService = null,
		?OptionProvider $optionProvider = null,
	)
	{
		$this->optionService = $optionService ?? OptionService::getInstance();
		$this->optionProvider = $optionProvider ?? OptionProvider::getInstance();
	}

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
		$this->optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_BANNER_COUNT,
			(string)($this->getShownCount($userId) + 1),
		);
		$this->optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_BANNER_LAST_SHOWN_AT,
			(string)time(),
		);

		$this->optionProvider->invalidateCache($userId);
	}

	public function enableAll(): void
	{
		if (Feature::isGeoFeaturesAvailableByRegion())
		{
			(new CheckInSettingsProvider())->enableAutoCheckIn();

			WorkDayService::enableDayStartCheckIn();
		}

		WorkDayService::enableWorkShiftOption();

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
		$this->optionService->save($userId, Option::CHECKIN_ONBOARDING_ENABLED, 'Y');
		$this->optionProvider->invalidateCache($userId);
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
		return $this->optionProvider->getOption($userId, $option)?->getValue();
	}

	public function isEmployeeOnboardingShown(int $userId): bool
	{
		return $this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_EMPLOYEE_SHOWN) === 'Y';
	}

	public function markEmployeeOnboardingShown(int $userId): void
	{
		$this->optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_EMPLOYEE_SHOWN,
			'Y',
		);

		$this->optionProvider->invalidateCache($userId);
	}

	public function getEmployeeAhaResumeIndex(int $userId): int
	{
		return (int)($this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_EMPLOYEE_AHA_RESUME) ?? 0);
	}

	public function setEmployeeAhaResumeIndex(int $userId, int $index): void
	{
		$this->optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_EMPLOYEE_AHA_RESUME,
			(string)$index,
		);

		$this->optionProvider->invalidateCache($userId);
	}

	public function getEmployeeGeoAhaState(int $userId): ?string
	{
		$state = $this->getOptionValue($userId, Option::CHECKIN_ONBOARDING_EMPLOYEE_GEO_AHA_STATE);

		return in_array($state, self::GEO_AHA_STATES, true) ? $state : null;
	}

	public function setEmployeeGeoAhaState(int $userId, string $state): void
	{
		if (!in_array($state, self::GEO_AHA_STATES, true))
		{
			return;
		}

		$currentState = $this->getEmployeeGeoAhaState($userId);

		if ($currentState === $state || $currentState === self::GEO_AHA_STATE_SHOWN)
		{
			return;
		}

		$this->optionService->save(
			$userId,
			Option::CHECKIN_ONBOARDING_EMPLOYEE_GEO_AHA_STATE,
			$state,
		);

		$this->optionProvider->invalidateCache($userId);
	}

	public function canShowMobileOnboardingFor(int $userId): bool
	{
		if (DepartmentProvider::isHeadOrDeputyOfDepartment($userId))
		{
			return !$this->isEnabledByUser($userId);
		}

		if ($this->isEmployeeOnboardingShown($userId))
		{
			return false;
		}

		if (!WorkDayService::isDayStartCheckInEnabled())
		{
			return false;
		}

		return (new NodeSettingsService())->isAutoCheckInEnabled($userId);
	}

	/**
	 * Tells whether auto check-in is already enabled in every node the user manages.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function isCheckInEnabledForHierarchy(int $userId): bool
	{
		if ($userId <= 0 || !Feature::isGeoFeaturesAvailableByRegion())
		{
			return false;
		}

		if (!DepartmentProvider::isHeadOrDeputyOfDepartment($userId))
		{
			return false;
		}

		return (new NodeSettingsService())->isAutoCheckInEnabledInSettings($userId);
	}
}
