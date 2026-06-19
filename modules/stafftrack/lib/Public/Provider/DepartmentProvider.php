<?php

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider as InternalDepartmentProvider;

class DepartmentProvider
{
	public static function getDepartmentIdsByUserId(int $userId): array
	{
		return InternalDepartmentProvider::getDepartmentIdsByUserId($userId);
	}

	public static function getDepartmentHeadsIdsByUserId(int $userId): array
	{
		return InternalDepartmentProvider::getDepartmentHeadsIdsByUserId($userId);
	}

	public static function getSubordinateIds(int $userId): array
	{
		return InternalDepartmentProvider::getSubordinateIds($userId);
	}

	public static function hasSubordinates(int $userId): bool
	{
		return !empty(self::getSubordinateIds($userId));
	}

	public static function isMySubordinate(int $targetUserId): bool
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		$subordinateIds = self::getSubordinateIds($currentUserId);

		return in_array($targetUserId, $subordinateIds, true);
	}
}