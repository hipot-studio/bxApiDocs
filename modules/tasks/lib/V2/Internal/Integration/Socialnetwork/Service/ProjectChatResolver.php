<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use Bitrix\Tasks\V2\Internal\Entity\Group;

class ProjectChatResolver
{
	public function getParentChatIdForGroup(?Group $group): int
	{
		$groupId = (int)($group?->id ?? 0);
		if ($groupId <= 0)
		{
			return 0;
		}

		if (!Loader::includeModule('socialnetwork'))
		{
			return 0;
		}

		$projectProvider = ServiceLocator::getInstance()->get(ProjectProvider::class);
		if (!$projectProvider->isProject($groupId))
		{
			return 0;
		}

		$project = $projectProvider->getById($groupId);

		return (int)($project?->chatId ?? 0);
	}
}
