<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Permission\Policy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Public\Provider\Counter\ChatCountersProvider;
use Bitrix\Im\V2\Public\Provider\Params\Counter\ChatCountersParams;

class ReadChatPolicy implements ActionAccessPolicy
{
	public function __construct(
		private readonly ChatCountersProvider $countersProvider,
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

		$counter = $this->countersProvider
			->get(ChatCountersParams::forChats($userId, [$chatId]))
			->getTotalCountForChat($chatId) ?? 0
		;

		return $counter > 0;
	}
}
