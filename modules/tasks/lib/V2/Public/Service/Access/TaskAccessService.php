<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service\Access;

use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\V2\Internal\Access;
use Bitrix\Tasks\V2\Internal\Entity;

class TaskAccessService
{
	private readonly Access\Service\TaskAccessService $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->get(Access\Service\TaskAccessService::class);
	}

	public function canSave(int $userId, Entity\Task $task): bool
	{
		return $this->delegate->canSave($userId, $task);
	}
}
