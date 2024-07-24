<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckActionAccess extends Base
{
	private string $actionName;

	public function __construct(string $actionName)
	{
		parent::__construct();
		$this->actionName = $actionName;
	}

	public function onBeforeAction(Event $event)
	{
		$chat = $this->getChat();
		if (!$chat instanceof Chat)
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::NOT_FOUND));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!$chat->canDo($this->actionName))
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	private function getChat(): ?Chat
	{
		$arguments = $this->getAction()->getArguments();

		return $arguments['chat'] ?? $arguments['message']?->getChat() ?? $arguments['messages']?->getCommonChat() ?? null;
	}
}