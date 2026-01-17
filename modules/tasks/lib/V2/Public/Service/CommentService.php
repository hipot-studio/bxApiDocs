<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service;

use Bitrix\Tasks\Comments\Internals\Comment;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;

class CommentService
{
	private readonly \Bitrix\Tasks\V2\Internal\Service\CommentService $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->get(\Bitrix\Tasks\V2\Internal\Service\CommentService::class);
	}

	public function send(Entity\Task $task, Comment $comment): void
	{
		$this->delegate->send($task, $comment);
	}
}
