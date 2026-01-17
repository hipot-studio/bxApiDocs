<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service;

use Bitrix\Tasks\V2\Internal\DI\Container;

class LinkService
{
	private readonly \Bitrix\Tasks\V2\Internal\Service\Link\LinkService $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->getLinkService();
	}

	public function getForumComments(int $taskId): string
	{
		return $this->delegate->getForumComments($taskId);
	}

	public function getListTask(int $userId = 0, int $groupId = 0): string
	{
		return $this->delegate->getListTask($userId, $groupId);
	}
}
