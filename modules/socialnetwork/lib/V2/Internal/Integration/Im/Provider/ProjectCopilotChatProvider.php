<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class ProjectCopilotChatProvider
{
	public const ENTITY_TYPE = 'SONET_PROJECT_COPILOT';

	private const CHAT_TITLE_MESSAGE_CODE = 'SOCIALNETWORK_PROJECT_COPILOT_CHAT_TITLE';

	private const CACHE_TTL = 86400 * 7;

	public function getByUserAndProject(int $userId, int $projectId, int $parentChatId): int
	{
		if ($userId <= 0 || $projectId <= 0 || $parentChatId <= 0 || !$this->isAvailable())
		{
			return 0;
		}

		$cacheManager = Application::getInstance()->getManagedCache();
		$cacheId = $this->getCacheId($userId, $projectId);

		if ($cacheManager->read(self::CACHE_TTL, $cacheId))
		{
			$chatId = (int)$cacheManager->get($cacheId);
			if ($chatId > 0 && Chat::getInstance($chatId) instanceof CopilotChat)
			{
				return $chatId;
			}

			$cacheManager->clean($cacheId);
		}

		$addParams = [
			'TYPE' => Chat::IM_TYPE_COPILOT,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => (string)$projectId,
			'AUTHOR_ID' => $userId,
			'PARENT_ID' => $parentChatId,
			'USERS' => [$userId],
			'SEND_GREETING_MESSAGES' => 'Y',
		];

		$title = Loc::getMessage(self::CHAT_TITLE_MESSAGE_CODE);
		if ($title !== null)
		{
			$addParams['TITLE'] = $title;
		}

		$addResult = ChatFactory::getInstance()->withContextUser($userId)->addUniqueChatPerAuthor($addParams);

		if (!$addResult->isSuccess())
		{
			return 0;
		}

		$chatId = (int)($addResult->getResult()['CHAT_ID'] ?? 0);
		if ($chatId > 0)
		{
			$cacheManager->set($cacheId, $chatId);
		}

		return $chatId;
	}

	private function getCacheId(int $userId, int $projectId): string
	{
		return "socialnetwork_project_copilot_u{$userId}_p{$projectId}";
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
