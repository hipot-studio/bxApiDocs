<?php

namespace Bitrix\StaffTrack\Controller\Trait;

use Bitrix\StaffTrack\Internal\Integration\Intranet\UserService;

trait IntranetUserTrait
{
	protected function isIntranetUser(int $userId): bool
	{
		return UserService::isIntranetUser($userId);
	}
}
