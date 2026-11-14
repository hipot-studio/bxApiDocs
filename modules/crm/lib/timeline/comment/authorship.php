<?php

namespace Bitrix\Crm\Timeline\Comment;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentEntry;

class Authorship
{
	public function isCurrentUserAuthor(int $commentId): bool
	{
		$comment = CommentEntry::getByID($commentId);

		return (int)$comment['AUTHOR_ID'] === Container::getInstance()->getContext()->getUserId();
	}
}
