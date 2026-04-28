<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Event\Legacy;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\PrivateChat;
use Bitrix\Im\V2\Common\Event\BaseLegacyEvent;

class ReadMessages extends BaseLegacyEvent
{
	public function __construct(Chat $chat, int $previousLastId, int $counter, int $userId, bool $byEvent)
	{
		$type = $chat instanceof PrivateChat ? 'OnAfterUserRead' : 'OnAfterChatRead';
		$parameters = [
			'CHAT_ID' => $chat->getId(),
			'START_ID' => $previousLastId,
			'END_ID' => $chat->getLastId(),
			'COUNT' => $counter,
			'USER_ID' => $userId,
			'BY_EVENT' => $byEvent,
		];

		if ($chat instanceof PrivateChat)
		{
			$parameters['DIALOG_ID'] = $chat->getDialogId();
			$parameters['CHAT_ENTITY_TYPE'] = 'USER';
			$parameters['CHAT_ENTITY_ID'] = '';
		}
		else
		{
			$parameters['CHAT_ENTITY_TYPE'] = $chat->getEntityType();
			$parameters['CHAT_ENTITY_ID'] = $chat->getEntityId();
		}

		parent::__construct($type, $parameters);
	}
}
