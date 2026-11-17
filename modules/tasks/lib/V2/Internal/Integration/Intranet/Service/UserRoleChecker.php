<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Intranet\Service;

use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Service\UserRoleCheckerInterface;

class UserRoleChecker implements UserRoleCheckerInterface
{
	public function isSuper(int $userId): bool
	{
		return User::isSuper($userId);
	}

	public function isBoss(int $userId, int $bossId): bool
	{
		return User::isBoss($userId, $bossId);
	}
}
