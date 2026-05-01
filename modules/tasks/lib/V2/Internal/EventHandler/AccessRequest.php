<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler;

use Bitrix\Main\EventResult;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\AccessRequestService;

final class AccessRequest
{
	public static function OnAfterUserDelete(int $userId): EventResult
	{
		Container::getInstance()->get(AccessRequestService::class)->clearAccessRequestsByUserId($userId);

		return new EventResult(EventResult::SUCCESS);
	}

	public static function OnTaskDelete(int $taskId): EventResult
	{
		Container::getInstance()->get(AccessRequestService::class)->clearAccessRequestsByTaskId($taskId);

		return new EventResult(EventResult::SUCCESS);
	}
}
