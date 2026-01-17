<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Service\Access;

use Bitrix\Tasks\DI\Container;
use Bitrix\Tasks\V2\Internal\Access;

class CheckListAccessService
{
	private readonly Access\Service\CheckListAccessService $delegate;

	public function __construct()
	{
		$this->delegate = Container::getInstance()->get(Access\Service\CheckListAccessService::class);
	}

	public function canAdd(int $userId, int $taskId): bool
	{
		return $this->delegate->canAdd($userId, $taskId);
	}
}
