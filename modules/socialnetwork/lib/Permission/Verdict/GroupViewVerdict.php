<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Verdict;

use Bitrix\Socialnetwork\UserToGroupTable;

final class GroupViewVerdict
{
	public static function canView(
		bool $isVisibleFlagOn,
		bool $isExtranetGroup,
		bool $isExtranetUser,
		?string $currentUserRole,
		bool $isModuleAdmin,
	): bool
	{
		return $isModuleAdmin
			|| self::isVisibleFor($isVisibleFlagOn, $isExtranetGroup, $isExtranetUser)
			|| self::isMember($currentUserRole)
		;
	}

	/**
	 * Container visibility outside the extranet site grants no access to extranet users.
	 */
	public static function isVisibleFor(bool $isVisibleFlagOn, bool $isExtranetGroup, bool $isExtranetUser): bool
	{
		return $isVisibleFlagOn && ($isExtranetGroup || !$isExtranetUser);
	}

	private static function isMember(?string $currentUserRole): bool
	{
		return $currentUserRole !== null
			&& in_array($currentUserRole, UserToGroupTable::getRolesMember(), true)
		;
	}
}
