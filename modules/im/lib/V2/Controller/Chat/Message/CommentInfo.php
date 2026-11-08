<?php

namespace Bitrix\Im\V2\Controller\Chat\Message;

use Bitrix\Im\V2\Chat\Comment\CommentPopupItem;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\MessageCollection;

class CommentInfo extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.CommentInfo.list
	 */
	public function listAction(MessageCollection $messages): ?array
	{
		// The thread-capability gate stays off here: the method returns cells regardless of whether
		// the parent is thread-capable. SameChatMessageFilter keeps the input single-chat.
		$commentInfo = new CommentPopupItem((int)$messages->getCommonChatId(), $messages->getIds());

		return $this->toRestFormat($commentInfo);
	}
}