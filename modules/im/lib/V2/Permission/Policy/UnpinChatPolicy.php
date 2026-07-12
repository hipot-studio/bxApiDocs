<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Recent\RecentProvider;

class UnpinChatPolicy implements ActionAccessPolicy
{
	public function __construct(
		private readonly RecentProvider $recentProvider,
	) {}

	public function check(Chat $chat, int $userId, Action $action, mixed $target): ?bool
	{
		$chatId = (int)$chat->getChatId();
		if ($userId <= 0 || $chatId <= 0)
		{
			return false;
		}

		if ($chat->checkAccess($userId)->isSuccess())
		{
			return true;
		}

		return $this->recentProvider->getItem($userId, $chatId) !== null;
	}
}
