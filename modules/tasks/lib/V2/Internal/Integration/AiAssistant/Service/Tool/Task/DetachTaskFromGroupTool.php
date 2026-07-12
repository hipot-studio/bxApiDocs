<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\UpdateTaskDto;

class DetachTaskFromGroupTool extends BaseUpdateTaskTool
{
	public const ACTION_NAME = 'detach_task_from_group';

	public function canRun(int $userId): bool
	{
		parent::canRun($userId);

		if (!$this->toolService->isProjectsAvailable())
		{
			throw new McpException(
				'The Projects feature in Tasks has been disabled in this Bitrix24 by the administrator, '
				. 'so a task cannot be detached from a project. '
				. 'Contact the administrator of this Bitrix24 to re-enable Projects',
			);
		}

		return true;
	}

	public function getDescription(): string
	{
		return 'Detaches a task from a group.';
	}

	protected function buildDto(array $args, int $userId): UpdateTaskDto
	{
		return UpdateTaskDto::fromArray([...$args, 'groupId' => 0]);
	}
}
