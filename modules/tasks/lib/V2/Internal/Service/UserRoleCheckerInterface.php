<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

interface UserRoleCheckerInterface
{
	public function isSuper(int $userId): bool;

	public function isBoss(int $userId, int $bossId): bool;
}
