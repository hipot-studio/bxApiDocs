<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping;

use Bitrix\Tasks\Comments\Task\CommentPoster;

class ForumCommentPing implements PingActionInterface
{
	public function execute(int $taskId, int $userId, array $taskData): void
	{
		$commentPoster = CommentPoster::getInstance($taskId, $userId);

		if (!$commentPoster)
		{
			return;
		}

		$commentPoster->postCommentsOnTaskStatusPinged($taskData);
	}
}
