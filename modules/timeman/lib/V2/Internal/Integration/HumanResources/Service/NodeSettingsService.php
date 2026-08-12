<?php

namespace Bitrix\Timeman\V2\Internal\Integration\HumanResources\Service;

use Bitrix\HumanResources\Public\Service\Container as HrContainer;
use Bitrix\HumanResources\Public\Service\NodeSettingsService as HrNodeSettingsService;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Loader;

final class NodeSettingsService
{
	public function isShiftsEnabled(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabled($service, $userId, NodeSettingsType::WelcomeBox);
	}

	public function isShiftsEnabledInSettings(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabledInSettings($service, $userId, NodeSettingsType::WelcomeBox);
	}

	public function canChangeShiftsSetting(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->canChangeOption($service, $userId, NodeSettingsType::WelcomeBox);
	}

	public function enableShifts(int $userId): void
	{
		$this->getHrService()?->setWelcomeBoxForMyManagedDepartments($userId, true);
	}

	public function disableShifts(int $userId): void
	{
		$this->getHrService()?->setWelcomeBoxForMyManagedDepartments($userId, false);
	}

	public function isDayStartCheckInEnabled(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabled($service, $userId, NodeSettingsType::DayStartCheckinRequired);
	}

	public function isDayStartCheckInEnabledInSettings(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabledInSettings($service, $userId, NodeSettingsType::DayStartCheckinRequired);
	}

	public function canChangeDayStartCheckIn(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->canChangeOption($service, $userId, NodeSettingsType::DayStartCheckinRequired);
	}

	public function enableDayStartCheckIn(int $userId): void
	{
		$this->getHrService()?->setDayStartCheckinRequiredForMyManagedDepartments($userId, true);
	}

	public function disableDayStartCheckIn(int $userId): void
	{
		$this->getHrService()?->setDayStartCheckinRequiredForMyManagedDepartments($userId, false);
	}

	public function isReportsEnabled(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabled($service, $userId, NodeSettingsType::AiReports);
	}

	public function hasAiReportAccess(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return in_array(true, $service->getAiReportsSettingsForManagedDepartments($userId), true);
	}

	public function isReportsEnabledInSettings(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabledInSettings($service, $userId, NodeSettingsType::AiReports);
	}

	public function canChangeReportsSetting(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->canChangeOption($service, $userId, NodeSettingsType::AiReports);
	}

	public function enableReports(int $userId): void
	{
		$this->getHrService()?->setAiReportsForMyManagedDepartments($userId, true);
	}

	public function disableReports(int $userId): void
	{
		$this->getHrService()?->setAiReportsForMyManagedDepartments($userId, false);
	}

	private function isOptionEnabled(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): bool
	{
		foreach ($this->loadMyDepartments($service, $userId, $type) as $bucket)
		{
			if (self::anyTrue($bucket))
			{
				return true;
			}
		}

		return false;
	}

	private function isOptionEnabledInSettings(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): bool
	{
		$managed = $this->loadManagedDepartments($service, $userId, $type);
		if (!empty($managed))
		{
			return self::allTrue($managed);
		}

		foreach ($this->loadMyDepartments($service, $userId, $type) as $bucket)
		{
			if (self::anyTrue($bucket))
			{
				return true;
			}
		}

		return false;
	}

	private function canChangeOption(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): bool
	{
		return !empty($this->loadManagedDepartments($service, $userId, $type));
	}

	private function loadManagedDepartments(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): array
	{
		return match ($type)
		{
			NodeSettingsType::WelcomeBox => $service->getWelcomeBoxSettingsForManagedDepartments($userId),
			NodeSettingsType::AiReports => $service->getAiReportsSettingsForManagedDepartments($userId),
			NodeSettingsType::DayStartCheckinRequired => $service->getDayStartCheckinRequiredSettingsForManagedDepartments($userId),
			default => [],
		};
	}

	private function loadMyDepartments(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): array
	{
		return match ($type)
		{
			NodeSettingsType::WelcomeBox => $service->getWelcomeBoxSettingsForMyDepartments($userId),
			NodeSettingsType::AiReports => $service->getAiReportsSettingsForMyDepartments($userId),
			NodeSettingsType::DayStartCheckinRequired => $service->getDayStartCheckinRequiredSettingsForMyDepartments($userId),
			default => [],
		};
	}

	private function getHrService(): ?HrNodeSettingsService
	{
		if (!Loader::includeModule('humanresources'))
		{
			return null;
		}

		return HrContainer::getNodeSettingsService();
	}

	private static function anyTrue(array $values): bool
	{
		return in_array(true, $values, true);
	}

	private static function allTrue(array $values): bool
	{
		return !in_array(false, $values, true) && !in_array(null, $values, true);
	}
}
