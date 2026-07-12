<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Factory;

use Bitrix\Im;

class Chat
{
	public function getExistedChat(int $chatId): ?Im\V2\Chat
	{
		$chat = Im\V2\Chat::getInstance($chatId);

		return $chat->isExist() ? $chat : null;
	}
}
