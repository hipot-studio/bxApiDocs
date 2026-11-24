<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Guards im.v2.Chat.extendPullWatch. Unlike ChatTypeFilter it asks a predicate instead of a list of
 * classes: thread capability depends on the entity type of a chat and is decided by the relation
 * registry of the tree, so a list here would become a second source of truth and drift away from it.
 */
class ExtendPullWatchTypeFilter extends Base
{
	/**
	 * Admissible set of the action: the chat either carries the comment tag of a thread-capable parent
	 * or broadcasts by a messages tag of its own. Those are the two kinds of subscription the method
	 * renews, and it branches on the same predicates, so the filter and the body cannot diverge.
	 */
	public static function hasExtendablePullWatch(Chat $chat): bool
	{
		return $chat->allowsComments() || $chat->needToSendPublicPull();
	}

	public function onBeforeAction(Event $event)
	{
		$chat = $this->getAction()->getArguments()['chat'] ?? null;

		if ($chat instanceof Chat && self::hasExtendablePullWatch($chat))
		{
			return null;
		}

		$this->addError(new ChatError(ChatError::WRONG_TYPE));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
