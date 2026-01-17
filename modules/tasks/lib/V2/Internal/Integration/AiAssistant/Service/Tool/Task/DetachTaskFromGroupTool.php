<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;

class DetachTaskFromGroupTool extends BaseUpdateTaskTool
{
	public const ACTION_NAME = 'detach_task_from_group';

	public function getDescription(): string
	{
		return 'Detaches a task from a group.';
	}

	protected function buildDto(array $args, int $userId): UpdateTaskDto
	{
		return UpdateTaskDto::fromArray([...$args, 'groupId' => 0]);
	}
}
