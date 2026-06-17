<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Public\Provider\ProjectProvider;
use Throwable;

class ProjectChatProvider
{
	public function getChatId(int $projectId): int
	{
		if ($projectId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return 0;
		}

		try
		{
			$projectProvider = ServiceLocator::getInstance()->get(ProjectProvider::class);
			if (!$projectProvider->isProject($projectId))
			{
				return 0;
			}

			$project = $projectProvider->getById($projectId);

			return $project?->chatId ?? 0;
		}
		catch (Throwable)
		{
			return 0;
		}
	}
}
