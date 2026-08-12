<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources\Service;

use Bitrix\HumanResources\Public\Service\Container as HrContainer;
use Bitrix\HumanResources\Public\Service\NodeSettingsService as HrNodeSettingsService;
use Bitrix\HumanResources\Type\NodeSettingsType;
use Bitrix\Main\Loader;

final class NodeSettingsService
{
	public function isAutoCheckInEnabled(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabled($service, $userId, NodeSettingsType::AutoCheckin);
	}

	public function isAutoCheckInEnabledInSettings(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->isOptionEnabledInSettings($service, $userId, NodeSettingsType::AutoCheckin);
	}

	public function canChangeAutoCheckIn(int $userId): bool
	{
		$service = $this->getHrService();
		if ($service === null)
		{
			return false;
		}

		return $this->canChangeOption($service, $userId, NodeSettingsType::AutoCheckin);
	}

	public function enableAutoCheckIn(int $userId): void
	{
		$this->getHrService()?->setAutoCheckinForMyManagedDepartments($userId, true);
	}

	public function disableAutoCheckIn(int $userId): void
	{
		$this->getHrService()?->setAutoCheckinForMyManagedDepartments($userId, false);
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
			NodeSettingsType::AutoCheckin => $service->getAutoCheckinSettingsForManagedDepartments($userId),
			default => [],
		};
	}

	private function loadMyDepartments(HrNodeSettingsService $service, int $userId, NodeSettingsType $type): array
	{
		return match ($type)
		{
			NodeSettingsType::AutoCheckin => $service->getAutoCheckinSettingsForMyDepartments($userId),
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
