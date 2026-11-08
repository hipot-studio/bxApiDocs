<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Integration\SocialNetwork\Group;
use Bitrix\Tasks\Scrum\Service\EpicService;
use Bitrix\Tasks\V2\Infrastructure\Controller\Response\Scrum\TaskInfoResponse;
use Bitrix\Tasks\V2\Internal\Entity;

class Scrum extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Scrum.getTaskInfo
	 */
	#[CloseSession]
	public function getTaskInfoAction(int $taskId): TaskInfoResponse
	{
		$item = $this->getItem($taskId);
		if (!$item)
		{
			return new TaskInfoResponse();
		}

		$epic = null;
		if ($item['epicId'] > 0)
		{
			$epic = $this->getEpic($item['epicId']);
		}

		$groupId = (int)($item['groupId'] ?? 0);
		$canCreateEpic = $groupId > 0 && Group::can($groupId, Group::ACTION_CREATE_TASKS, $this->userId);
		$canUpdateScrumFields = $groupId > 0
			&& Group::canReadGroupTasks($this->userId, $groupId)
			&& TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_EDIT, $taskId);

		return new TaskInfoResponse(
			storyPoints: $item['storyPoints'],
			epic: $epic ? Entity\Epic::mapFromArray($epic) : null,
			canCreateEpic: $canCreateEpic,
			canUpdateScrumFields: $canUpdateScrumFields,
		);
	}

	/**
	 * @ajaxAction tasks.V2.Scrum.getEpicCreationRight
	 */
	#[CloseSession]
	public function getEpicCreationRightAction(int $groupId): bool
	{
		// A create-mode draft has no real taskId yet, so the create-epic footer cannot resolve its
		// right through getTaskInfo. The right does not depend on the task: it is the same gate used
		// by getTaskInfo above and by Scrum\Epic::createEpic, so it can be answered by groupId alone.
		return $groupId > 0 && Group::can($groupId, Group::ACTION_CREATE_TASKS, $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Scrum.updateTask
	 */
	public function updateTaskAction(Entity\Task $task): void
	{
		$this->forward(
			new \Bitrix\Tasks\Rest\Controllers\Scrum\Task(),
			'update',
			[
				'id' => $task->id,
				'fields' => [
					'epicId' => $task->epicId,
					'storyPoints' => $task->storyPoints,
				],
			],
		);
	}

	private function getItem(int $taskId): ?array
	{
		return $this->forward(
			new \Bitrix\Tasks\Rest\Controllers\Scrum\Task(),
			'get',
			[
				'id' => $taskId,
			],
		);
	}

	private function getEpic(int $id): ?array
	{
		$epic = (new EpicService())->getEpic($id);
		if (!$epic->getId())
		{
			return null;
		}

		$isRestricted = !Group::canReadGroupTasks($this->userId, $epic->getGroupId());
		$result = [
			'id' => $epic->getId(),
			'isRestricted' => $isRestricted,
		];

		if (!$isRestricted)
		{
			$result['name'] = $epic->getName();
			$result['color'] = $epic->getColor();
		}

		return $result;
	}
}
