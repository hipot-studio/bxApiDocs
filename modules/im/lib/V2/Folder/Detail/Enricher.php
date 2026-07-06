<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Detail;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\NullChat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Folder\Folder;
use Bitrix\Im\V2\Folder\PersonalFolder;

class Enricher
{
	public function enrich(Folder $folder, int $userId): DetailResult
	{
		$result = new DetailResult();

		if (!$folder instanceof PersonalFolder)
		{
			return $result->setDetail(new FolderDetail($folder, [], []));
		}

		$chats = $this->loadAccessibleChats($folder->getChatIds(), $userId);
		$companionMap = $this->resolveCompanionMap($chats, $userId);
		$this->fillDialogIds($chats, $companionMap);

		$userIds = array_values(array_map('intval', $companionMap));

		return $result->setDetail(new FolderDetail($folder, $chats, $userIds));
	}

	/**
	 * @param int[] $chatIds
	 * @return Chat[]
	 */
	private function loadAccessibleChats(array $chatIds, int $userId): array
	{
		$chats = [];
		foreach ($chatIds as $chatId)
		{
			$chat = Chat::getInstance($chatId);
			if ($chat instanceof NullChat || !$chat->isExist())
			{
				continue;
			}
			if (!$chat->checkAccess($userId)->isSuccess())
			{
				continue;
			}
			$chats[] = $chat;
		}

		Chat::fillSelfRelations($chats, $userId);

		return $chats;
	}

	/**
	 * @param Chat[] $chats
	 * @return array<int, int|string> chatId => companionUserId
	 */
	private function resolveCompanionMap(array $chats, int $userId): array
	{
		$privateChatIds = [];
		foreach ($chats as $chat)
		{
			if ($chat instanceof PrivateChat)
			{
				$privateChatIds[] = (int)$chat->getChatId();
			}
		}

		if (empty($privateChatIds))
		{
			return [];
		}

		return PrivateChat::getDialogIds($privateChatIds, $userId);
	}

	/**
	 * @param Chat[] $chats
	 * @param array<int, int|string> $companionMap chatId => companionUserId
	 */
	private function fillDialogIds(array $chats, array $companionMap): void
	{
		if (empty($companionMap))
		{
			return;
		}

		$privateChatsByChatId = [];
		foreach ($chats as $chat)
		{
			if ($chat instanceof PrivateChat)
			{
				$privateChatsByChatId[(int)$chat->getChatId()] = $chat;
			}
		}

		foreach ($companionMap as $chatId => $companionId)
		{
			$privateChatsByChatId[(int)$chatId]?->setDialogId((string)$companionId);
		}
	}
}
