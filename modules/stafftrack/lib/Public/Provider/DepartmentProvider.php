<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider as InternalDepartmentProvider;

class DepartmentProvider
{
	/**
	 * Managed-hierarchy answers, keyed by manager and then by employee. Only pays off in a long-lived
	 * process building many commands for one author (agent, queue, seeder) - a request asks about a pair
	 * once anyway. There it is a risk as well: it lives to the end of the process, has no reset, and the
	 * reset humanresources fires on a change of the structure does not reach it.
	 *
	 * @var array<int, array<int, bool>>
	 */
	private static array $managerAnswerCache = [];

	public static function getDepartmentIdsByUserId(int $userId): array
	{
		return InternalDepartmentProvider::getDepartmentIdsByUserId($userId);
	}

	public static function getDepartmentHeadsIdsByUserId(int $userId): array
	{
		return InternalDepartmentProvider::getDepartmentHeadsIdsByUserId($userId);
	}

	/**
	 * @param int[] $employeeIds
	 * @return int[]
	 */
	public static function getManagerIdsForEmployees(array $employeeIds): array
	{
		return InternalDepartmentProvider::getManagerIdsForEmployees($employeeIds);
	}

	/**
	 * @param int[] $employeeIds
	 * @return int[]
	 */
	public static function getBranchManagerIdsForEmployees(array $employeeIds): array
	{
		return InternalDepartmentProvider::getBranchManagerIdsForEmployees($employeeIds);
	}

	/**
	 * @param int[] $employeeIds
	 * @return array<int, int[]>
	 */
	public static function getBranchManagerIdsByEmployee(array $employeeIds): array
	{
		return InternalDepartmentProvider::getBranchManagerIdsByEmployee($employeeIds);
	}

	public static function getSubordinateIds(int $userId): array
	{
		return InternalDepartmentProvider::getSubordinateIds($userId);
	}

	public static function getManagedDepartmentIds(int $userId): array
	{
		return InternalDepartmentProvider::getManagedDepartmentIds($userId);
	}

	public static function hasSubordinates(int $userId): bool
	{
		return !empty(self::getSubordinateIds($userId));
	}

	public static function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		return InternalDepartmentProvider::isHeadOrDeputyOfDepartment($userId);
	}

	public static function isAccessibleEmployee(int $viewerUserId, int $employeeUserId): bool
	{
		return InternalDepartmentProvider::isAccessibleEmployee($viewerUserId, $employeeUserId);
	}

	/**
	 * Whether the manager stands above the employee in the managed hierarchy: anywhere up the branch of
	 * the employee, at any depth, holding the higher role of the two. Unlike a subordinate list this is
	 * answered per pair rather than by materialising a list.
	 */
	public static function isManagerForEmployee(int $managerUserId, int $employeeUserId): bool
	{
		return self::$managerAnswerCache[$managerUserId][$employeeUserId]
			??= InternalDepartmentProvider::isManagerForEmployee($managerUserId, $employeeUserId);
	}

	/**
	 * @deprecated
	 * @see DepartmentProvider::isAccessibleEmployee()
	 */
	public static function isMySubordinate(int $targetUserId): bool
	{
		return self::isSubordinateOf($targetUserId, (int)CurrentUser::get()->getId());
	}

	public static function isSubordinateOf(int $userId, int $headUserId): bool
	{
		return in_array($userId, self::getSubordinateIds($headUserId), true);
	}

	public static function canReadCheckInsOf(int $readerUserId, int $authorUserId): bool
	{
		return self::isAccessibleEmployee($readerUserId, $authorUserId);
	}
}
