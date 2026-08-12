<?php
namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Item\Department;
use Bitrix\StaffTrack\Service\UserService;
use Bitrix\StaffTrack\Item\User;

class DepartmentProvider
{
	public static function getHeadId(int $userId): ?int
	{
		$departmentHeadIds = self::getDepartmentHeadsIdsByUserId($userId);
		$adminIds = self::getAdminIds();

		return $departmentHeadIds[0] ?? $adminIds[0] ?? null;
	}

	public static function getDepartmentIdsByUserId(int $userId): array
	{
		$user = self::getUserById($userId);
		if ($user === null)
		{
			return [];
		}

		return array_map(
			static fn (Department $department) => $department->id,
			$user->departments->getValues(),
		);
	}

	private static function getUserById(int $userId): ?User
	{
		return UserService::getInstance()->getUser($userId);
	}

	public static function getDepartmentHeadsIdsByUserId(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$nodeMemberCollection = HrPublicContainer::getUserDepartmentService()->getUserHeads($userId);

		$departmentHeadsIds = $nodeMemberCollection->getEntityIds();

		return array_reverse($departmentHeadsIds);
	}

	public static function getSubordinateIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return HrPublicContainer::getUserDepartmentService()->getSubordinateUserIds($userId);
	}

	/**
	 * IDs of DEPARTMENT nodes where the user is head or deputy head (no subtree).
	 *
	 * @return int[]
	 */
	public static function getManagedDepartmentIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return HrPublicContainer::getUserDepartmentService()
			->getNodeMembersWhereUserIsHeadOrDeputy($userId)
			->getNodeIds()
		;
	}

	public static function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		return HrPublicContainer::getUserDepartmentService()->isHeadOrDeputyOfDepartment($userId);
	}

	private static function getAdminIds(): array
	{
		$adminIds = [];
		if (Loader::includeModule('bitrix24'))
		{
			$adminIds = \CBitrix24::getAllAdminId();
		}
		else
		{
			$result = \CGroup::GetGroupUserEx(1);
			while ($row = $result->fetch())
			{
				$adminIds[] = (int)$row['USER_ID'];
			}
		}

		return $adminIds;
	}
}