<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Event\Legacy;

use Bitrix\Im\V2\Chat\Event\Legacy\Dto\Chat;
use Bitrix\Im\V2\Common\Event\BaseLegacyEvent;

class AfterChatMuteNotifyEvent extends BaseLegacyEvent
{
	public function __construct(\Bitrix\Im\V2\Chat $chat, int $userId, bool $isMuted)
	{
		parent::__construct('OnAfterChatMuteNotify', [
			'CHAT_ID' => $chat->getId(),
			'USER_ID' => $userId,
			'MUTE' => $isMuted,
			'CHAT' => (new Chat($chat, $userId))->toArray(),
		]);
	}
}
