<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Access;

use Bitrix\Im\V2\Entity\User\User;

class OpenChatAccessPolicy
{
	public function canSkipMembershipForOpenChats(int $userId): bool
	{
		return !User::getInstance($userId)->isExtranet();
	}
}
