<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\Main\Loader;

final class EmployeeIdChecker
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
	 * @return bool|null null when the employee status cannot be established
	 */
	public function containsEmployee(array $userIds): ?bool
	{
		try
		{
			if (!($this->moduleLoader)())
			{
				return null;
			}

			$service = ($this->serviceProvider)();
			if (!method_exists($service, 'isEmployee'))
			{
				return null;
			}

			foreach ($userIds as $userId)
			{
				if ($service->isEmployee((int)$userId))
				{
					return true;
				}
			}

			return false;
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
