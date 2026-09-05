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

		// Phase 0 (task 718250): a hidden (admin-access) participant is not a real project member.
		// Creating a per-author copilot chat cascades the author into the parent project chat as a
		// visible member, which would promote them into the project (Bug B). Skip such users; a real
		// member (present, not hidden) still gets the copilot chat as before.
		$parentRelation = Chat::getInstance($parentChatId)->getRelationByUserId($userId);
		if ($parentRelation === null || $parentRelation->isHidden())
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
			'MANAGE_DELETE' => Chat::MANAGE_RIGHTS_NONE,
		];

		$title = Loc::getMessage(self::CHAT_TITLE_MESSAGE_CODE);
		if ($title !== null)
		{
			$addParams['TITLE'] = $title;
		}

		// A system context (user 0): the copilot bot is invisible to a collaber, so the visibility
		// filters of the membership cascade would drop it before it reaches the project chat and the
		// chat would be born without anyone to answer in it. AUTHOR_ID above keeps both the authorship
		// and the uniqueness of the chat, neither of them depends on the context.
		$addResult = ChatFactory::getInstance()->withContextUser(0)->addUniqueChatPerAuthor($addParams);

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
