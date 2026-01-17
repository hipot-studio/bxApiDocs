<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Access;

use Bitrix\Main\Loader;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Public;

class CheckListAccessService
{
	private ?Public\Service\Access\CheckListAccessService $accessService = null;

	public function __construct()
	{
		if (Loader::includeModule('tasks'))
		{
			$this->accessService = Container::getInstance()->get(Public\Service\Access\CheckListAccessService::class);
		}
	}

	public function canAdd(int $userId, int $taskId): bool
	{
		return (bool)$this->accessService?->canAdd($userId, $taskId);
	}
}
