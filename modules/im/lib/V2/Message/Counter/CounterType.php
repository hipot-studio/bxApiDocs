<?php

namespace Bitrix\Im\V2\Message\Counter;

use Bitrix\Im\V2\Chat;

final class CounterType
{
	public const Chat = 'chat';
	public const Comment = 'comment';
	public const Copilot = 'copilot';
	public const Openline = 'openline';
	public const Collab = 'collab';

	public static function tryFromChat(Chat $chat): string
	{
		return match ($chat->getType())
		{
			Chat::IM_TYPE_COMMENT => self::Comment,
			Chat::IM_TYPE_OPEN_LINE => self::Openline,
			Chat::IM_TYPE_COPILOT => self::Copilot,
			Chat::IM_TYPE_COLLAB => self::Collab,
			default => self::Chat,
		};
	}
}
