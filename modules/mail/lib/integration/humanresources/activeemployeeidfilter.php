<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;

final class ActiveEmployeeIdFilter
{
	private \Closure $moduleLoader;
	private \Closure $serviceProvider;

	public function __construct(
		?\Closure $moduleLoader = null,
		?\Closure $serviceProvider = null,
	)
	{
		$this->moduleLoader = $moduleLoader ?? static fn(): bool => Loader::includeModule('humanresources');
		$this->serviceProvider = $serviceProvider ?? static fn(): object => Container::getUserDepartmentService();
	}

	/**
	 * @param int[] $userIds
	 * @return int[]|null
	 */
	public function filter(array $userIds): ?array
	{
		if (empty($userIds))
		{
			return [];
		}

		try
		{
			if (!($this->moduleLoader)())
			{
				return null;
			}

			$service = ($this->serviceProvider)();
			if (method_exists($service, 'filterActiveEmployeeIds'))
			{
				$result = $service->filterActiveEmployeeIds($userIds);

				return is_array($result) ? $result : null;
			}

			if (!method_exists($service, 'isEmployee'))
			{
				return null;
			}

			$employeeIds = [];
			foreach (array_unique(array_map('intval', $userIds)) as $userId)
			{
				if ($userId > 0 && $service->isEmployee($userId))
				{
					$employeeIds[] = $userId;
				}
			}

			return $employeeIds;
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
