<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;

class UpdateTaskTool extends BaseUpdateTaskTool
{
	public const ACTION_NAME = 'update_task';

	public function getDescription(): string
	{
		return 'Updates an existing task with the provided data. Requires task ID.';
	}

	protected function buildDto(array $args, int $userId): UpdateTaskDto
	{
		return UpdateTaskDto::fromArray([...$args, 'userId' => $userId]);
	}
}
