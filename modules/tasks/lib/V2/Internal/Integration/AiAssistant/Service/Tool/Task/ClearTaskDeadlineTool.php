<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;

class ClearTaskDeadlineTool extends BaseUpdateTaskTool
{
	public const ACTION_NAME = 'clear_task_deadline';

	public function getDescription(): string
	{
		return 'Clears the deadline for a task.';
	}

	protected function buildDto(array $args, int $userId): UpdateTaskDto
	{
		return UpdateTaskDto::fromArray([...$args, 'deadlineDate' => '']);
	}
}
