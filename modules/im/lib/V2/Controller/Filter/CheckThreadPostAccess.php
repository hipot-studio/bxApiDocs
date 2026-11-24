<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat\CommentChat;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Names the reason a discussion refuses a message, in front of the general CheckActionAccess, which
 * answers ACCESS_DENIED to every refusal alike and is shared by dozens of methods of the module.
 *
 * It only ever adds a name to a refusal that would happen anyway: CheckActionAccess keeps the last
 * word, and this filter stays silent for everything that is not a discussion and for every refusal a
 * discussion is not the deciding reason of, so no other path changes the answer it gives today.
 */
class CheckThreadPostAccess extends Base
{
	public function onBeforeAction(Event $event)
	{
		$error = $this->getThread()?->getThreadPostError();

		if ($error === null)
		{
			return null;
		}

		$this->addError($error);

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function getThread(): ?CommentChat
	{
		foreach ($this->getAction()->getArguments() as $argument)
		{
			if ($argument instanceof CommentChat)
			{
				return $argument;
			}
		}

		return null;
	}
}
