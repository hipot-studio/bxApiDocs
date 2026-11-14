<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;

class EmployeeRouteAccessService
{
	public function isIntranetUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return $this->isIntranetModuleIncluded() && Util::isIntranetUser($userId);
	}

	protected function isIntranetModuleIncluded(): bool
	{
		return Loader::includeModule('intranet');
	}

	public function isActiveEmployee(int $employeeId): bool
	{
		if ($employeeId <= 0)
		{
			return false;
		}

		$row = UserTable::query()
			->setSelect(['ID'])
			->where('ID', $employeeId)
			->where('ACTIVE', 'Y')
			->where('IS_REAL_USER', 'Y')
			->fetch()
		;

		return $row !== false && $row !== null;
	}

	public function isAccessibleEmployee(int $viewerId, int $employeeId): bool
	{
		return DepartmentProvider::isAccessibleEmployee($viewerId, $employeeId);
	}

	public function getTimezoneOffset(int $employeeId): int
	{
		return (int)\CTimeZone::GetOffset($employeeId, true);
	}
}
