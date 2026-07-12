<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectChatDataProvider;

class ProjectChatHider
{
	public function __construct(
		private readonly ProjectChatDataProvider $chatDataProvider,
	)
	{
	}

	/**
	 * @throws LoaderException
	 */
	public function hide(int $projectId): void
	{
		$this->hideForProjects([$projectId]);
	}

	/**
	 * @param int[] $projectIds
	 * @throws LoaderException
	 */
	public function hideForProjects(array $projectIds): void
	{
		if (empty($projectIds) || !Loader::includeModule('im'))
		{
			return;
		}

		$chatDataList = $this->chatDataProvider->getByProjectIds($projectIds);

		foreach ($chatDataList as $chatData)
		{
			$chatId = (int)($chatData['chatId'] ?? 0);
			if ($chatId <= 0)
			{
				continue;
			}

			$chat = Chat::getInstance($chatId);
			$dialogId = $chat->getDialogId();

			foreach ($chat->getRelations() as $relation)
			{
				Im\Recent::hide($dialogId, $relation->getUserId());
			}
		}
	}
}
