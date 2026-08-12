<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Event;

use Bitrix\Im\V2\Chat\Event\Dto\ChatDto;
use Bitrix\Im\V2\Common\Event\BaseEvent;

class AfterDeleteEvent extends BaseEvent
{
	public function __construct(ChatDto $chat)
	{
		parent::__construct('OnAfterChatDelete', ['chat' => $chat]);
	}

	public function getChat(): ChatDto
	{
		return $this->parameters['chat'];
	}
}
