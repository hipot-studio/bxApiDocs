<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Access;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Public;

class TaskAccessService
{
	private ?Public\Service\Access\TaskAccessService $accessService = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->accessService = Container::getInstance()->get(Public\Service\Access\TaskAccessService::class);
		}
	}

	public function canSave(int $userId, Task $task): bool
	{
		return (bool)$this->accessService?->canSave($userId, $task);
	}
}
