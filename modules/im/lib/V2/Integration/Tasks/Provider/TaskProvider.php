<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Provider;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Public;

class TaskProvider
{
	private ?Public\Provider\TaskProvider $taskProvider = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->taskProvider = Container::getInstance()->get(Public\Provider\TaskProvider::class);
		}
	}

	public function getTaskById(int $taskId, int $userId): ?Task
	{
		if (!Loader::includeModule('tasks'))
		{
			return null;
		}

		$taskParams = new Public\Provider\Params\TaskParams(taskId: $taskId, userId: $userId, members: true);

		return $this->taskProvider?->get($taskParams);
	}
}
