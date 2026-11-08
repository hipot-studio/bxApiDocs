<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;

final class ChatTypePolicy
{
	private const ALLOWED_CHAT_TYPES = [
		'private',
		'chat',
		'open',
		'general',
		'channel',
		'openChannel',
		'generalChannel',
		'comment',
		'collab',
		'tasksTask',
	];

	public static function isAllowed(Chat $chat): bool
	{
		$publicType = $chat->getChatType()->getExtendedType(camelCase: true);
		if (!in_array($publicType, self::ALLOWED_CHAT_TYPES, true))
		{
			return false;
		}

		if ($chat instanceof PrivateChat && $chat->getCompanion()->isBot())
		{
			return false;
		}

		return true;
	}
}
