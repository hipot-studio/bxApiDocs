<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Main\Loader;

class ProjectChatAncestorResolver
{
	public function getProjectIdByChatId(int $chatId): ?int
	{
		if ($chatId <= 0 || !Loader::includeModule('im'))
		{
			return null;
		}

		$projectChat = $this->findProjectChatAncestor($chatId);
		if ($projectChat === null)
		{
			return null;
		}

		$projectId = (int)$projectChat->getEntityId();

		return ($projectId > 0) ? $projectId : null;
	}

	private function findProjectChatAncestor(int $chatId): ?CollabChat
	{
		$visitedMap = [];

		$current = Chat::getInstance($chatId);
		while ($current !== null)
		{
			if ($current instanceof CollabChat)
			{
				return $current;
			}

			$currentId = $current->getChatId();
			if ($currentId === null || isset($visitedMap[$currentId]))
			{
				return null;
			}

			$visitedMap[$currentId] = true;

			$current = $current->getParentChat();
		}

		return null;
	}
}
