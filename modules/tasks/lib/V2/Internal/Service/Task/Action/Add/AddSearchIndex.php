<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

class AddSearchIndex
{
	public function __invoke(array $fullTaskData): void
	{
		$taskId = (int)$fullTaskData['ID'];

		(new Async\Message\AddSearchIndex(['ID' => $taskId]))->sendByTaskId($taskId);
	}
}
