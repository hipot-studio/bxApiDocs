<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Access;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Public;

class TaskAccessService
{
	private ?Public\Service\Access\TaskAccessService $accessService = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->accessService = ServiceLocator::getInstance()->get(Public\Service\Access\TaskAccessService::class);
		}
	}

	public function canRead(int $userId, int $taskId): bool
	{
		return (bool)$this->accessService?->canRead($userId, $taskId);
	}
}
