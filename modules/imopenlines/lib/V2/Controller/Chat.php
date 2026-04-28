<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\Im\V2\Controller\Chat\Pin;
use Bitrix\ImOpenLines\V2\Chat\OpenLineChatDecorator;
use Bitrix\Main\Loader;

Loader::requireModule('im');

class Chat extends \Bitrix\Im\V2\Controller\Chat
{
	/**
	 * @restMethod imopenlines.v2.Chat.load
	 */
	public function loadAction(
		\Bitrix\Im\V2\Chat $chat,
		int $messageLimit = \Bitrix\Im\V2\Controller\Chat\Message::DEFAULT_LIMIT,
		int $pinLimit = Pin::DEFAULT_LIMIT,
		string $ignoreMark = 'N'
	): ?array
	{
		$chatData = parent::loadAction($chat, $messageLimit, $pinLimit, $ignoreMark);
		if ($chat->getType() !== \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_LINE)
		{
			return $chatData;
		}

		$openLineChat = new OpenLineChatDecorator($chat);

		return array_merge($chatData, $openLineChat->toRestFormat());
	}
}
