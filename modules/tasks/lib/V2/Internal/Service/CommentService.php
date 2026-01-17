<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Tasks\Comments\Internals\Comment;
use Bitrix\Tasks\Comments\Task\CommentPoster;
use Bitrix\Tasks\V2\Internal\Entity;

class CommentService
{
	public function send(Entity\Task $task, Comment $comment): void
	{
		$poster = CommentPoster::getInstance($task->getId(), $comment->getAuthorId());
		if ($poster === null)
		{
			return;
		}

		$poster->clearComments();
		$poster->addComments([$comment]);
		$poster->disableDeferredPostMode();
		$poster->postComments();
	}
}
