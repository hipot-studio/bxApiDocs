<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Public\Provider;

use Bitrix\Calendar\Internal\Service\GroupCounterService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;

class GroupCounterProvider
{
	public function getGroupTotalMapByGroupIds(int $userId, array $groupIds): array
	{
		if ($userId < 1 || $userId !== (int)CurrentUser::get()->getId())
		{
			return [];
		}

		return ServiceLocator::getInstance()
			->get(GroupCounterService::class)
			->getGroupTotalMapByGroupIds($userId, $groupIds)
		;
	}
}
