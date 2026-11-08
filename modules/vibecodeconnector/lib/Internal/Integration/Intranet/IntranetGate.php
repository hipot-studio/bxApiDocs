<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Intranet;

use Bitrix\Intranet\Entity;
use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Main\Loader;

class IntranetGate
{
	public function __construct(private readonly ?HrUserService $hrUserService = null)
	{
	}

	public function isIntranet(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->fetchIsIntranet($userId);
	}

	public function filterIntranetUsers(array $userIds): array
	{
		return $this->resolveIntranetUsers($userIds)->employees;
	}

	public function resolveIntranetUsers(array $userIds): EmployeeDecision
	{
		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($userIds === [])
		{
			return new EmployeeDecision([], []);
		}

		$resolved = $this->fetchIntranetUserIds($userIds);
		if ($resolved === null)
		{
			return new EmployeeDecision([], $userIds);
		}

		$employeeIds = array_fill_keys($resolved, true);

		return new EmployeeDecision(
			array_values(array_filter(
				$userIds,
				static fn (int $userId): bool => isset($employeeIds[$userId]),
			)),
			[],
		);
	}

	protected function fetchIntranetUserIds(array $userIds): ?array
	{
		if (
			!Loader::includeModule('intranet')
			|| !Loader::includeModule('humanresources')
		)
		{
			return null;
		}

		return ($this->hrUserService ?? new HrUserService())->filterEmployeesByUserIds($userIds);
	}

	protected function fetchIsIntranet(int $userId): bool
	{
		if (
			!Loader::includeModule('intranet')
			|| !Loader::includeModule('humanresources')
		)
		{
			return false;
		}

		return (new Entity\User($userId))->isIntranet();
	}
}
