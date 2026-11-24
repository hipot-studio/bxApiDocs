<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Guards the actions addressing the threads of a chat. Unlike ChatTypeFilter it asks a predicate instead
 * of a list of classes: thread capability depends on the entity type of a chat and is decided by the
 * relation registry of the tree, so a list here would become a second source of truth and drift away.
 */
class ThreadCapableChatFilter extends Base
{
	/**
	 * The code of the refusal belongs to the action: readAll keeps answering with the one it has
	 * always answered with, while the list of threads answers with the code its contract names.
	 */
	public function __construct(private readonly string $errorCode = ChatError::WRONG_TYPE)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event)
	{
		$chat = $this->getAction()->getArguments()['chat'] ?? null;

		if ($chat instanceof Chat && $chat->allowsComments())
		{
			return null;
		}

		$this->addError(new ChatError($this->errorCode));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
