<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Ping;

interface PingActionInterface
{
	public function execute(int $taskId, int $userId, array $taskData): void;
}
