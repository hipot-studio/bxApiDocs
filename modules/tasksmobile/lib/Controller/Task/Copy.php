<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Controller\Task;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\TasksMobile\Controller\Base;
use Bitrix\TasksMobile\Exception\PermissionCheckFailedException;
use Bitrix\TasksMobile\Provider\ChecklistProvider;

final class Copy extends Base
{
	protected function getQueryActionNames(): array
	{
		return [
			'getSourceTaskData',
		];
	}

	public function getSourceTaskDataAction(int $taskId): array
	{
		$userId = (int)CurrentUser::get()->getId();

		$this->assertTaskReadPermission($taskId, $userId);

		return $this->convertKeysToCamelCase([
			'CHECKLIST' => (new ChecklistProvider())->getChecklistV2($taskId, Type::Task, true),
		]);
	}

	/**
	 * @throws PermissionCheckFailedException
	 */
	private function assertTaskReadPermission(int $taskId, int $userId): void
	{
		$result = TaskAccessController::can(
			$userId,
			ActionDictionary::ACTION_TASK_READ,
			$taskId,
		);

		if (!$result)
		{
			throw new PermissionCheckFailedException('Task is not accessible');
		}
	}
}
