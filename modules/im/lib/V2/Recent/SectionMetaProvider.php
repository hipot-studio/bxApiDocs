<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Collab\CollabInfo;

class SectionMetaProvider
{
	public function getMeta(?int $parentChatId, ?string $recentSection, int $userId): SectionMeta
	{
		$parentChat = Chat::getInstance($parentChatId);

		$fixedChatIds = $this->resolveFixedChatIds($parentChat, $recentSection, $userId);
		$collabInfo = $this->resolveCollabInfo($parentChat, $recentSection);

		return new SectionMeta(fixedChatIds: $fixedChatIds, collabInfo: $collabInfo);
	}

	private function resolveCollabInfo(Chat $parentChat, ?string $recentSection): ?CollabInfo
	{
		if (
			$recentSection === 'collabDefault'
			&& $parentChat instanceof Chat\CollabChat
		)
		{
			return new CollabInfo($parentChat);
		}

		return null;
	}

	private function resolveFixedChatIds(Chat $parentChat, ?string $recentSection, int $userId): array
	{
		if (!$parentChat instanceof Chat\ExternalChat)
		{
			return [];
		}

		return $parentChat->getRecentFixedChatIds($recentSection, $userId);
	}
}
