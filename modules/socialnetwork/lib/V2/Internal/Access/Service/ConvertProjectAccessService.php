<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;

class ConvertProjectAccessService
{
	public function canConvert(int $groupId, int $userId): bool
	{
		if ($groupId <= 0 || $userId <= 0)
		{
			return false;
		}

		return GroupAccessController::can($userId, GroupDictionary::VIEW, $groupId);
	}
}
