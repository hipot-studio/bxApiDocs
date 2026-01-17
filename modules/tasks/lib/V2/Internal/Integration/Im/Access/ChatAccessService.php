<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Access;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Loader;

class ChatAccessService
{
	public function canSendMessage(int $chatId, int $userId): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		$chat = Chat::getInstance($chatId);

		return $chat->withContextUser($userId)->canDo(Action::Send);
	}
}
