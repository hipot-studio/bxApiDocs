<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

class UpdateSearchIndex
{
	public function __invoke(array $fullTaskData, array $fields): void
	{
		$taskId = (int)$fullTaskData['ID'];

		(new Async\Message\UpdateSearchIndex(['ID' => $taskId]))->sendByTaskId($taskId);
	}
}
