<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\DiskArchiveLinkService;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class TaskProvider
{
	public function __construct(
		private readonly TaskReadRepositoryInterface $taskRepository,
		private readonly ChatRepositoryInterface $chatRepository,
		private readonly EgressInterface $egressController,
		private readonly ControllerFactoryInterface $controllerFactory,
		private readonly CrmAccessService $crmAccessService,
		private readonly LinkService $linkService,
		private readonly TaskRightService $taskRightService,
		private readonly DiskArchiveLinkService $diskArchiveLinkService,
	)
	{
	}

	public function getTaskById(TaskParams $taskParams): ?Task
	{
		if ($taskParams->checkTaskAccess)
		{
			$controller = $this->controllerFactory->create(Type::Task, $taskParams->userId);
			if (!$controller?->checkByItemId(ActionDictionary::ACTION_TASK_READ, $taskParams->taskId))
			{
				return null;
			}
		}

		$select = new Select(
			group: $taskParams->group,
			flow: $taskParams->flow,
			stage: $taskParams->stage,
			members: $taskParams->members,
			checkLists: $taskParams->checkLists,
			chat: $taskParams->chat,
			crm: $taskParams->crm,
			tags: $taskParams->tags,
			favorite: $taskParams->favorite,
			options: $taskParams->options,
			parameters: $taskParams->parameters,
		);

		$task = $this->taskRepository->getById(
			id: $taskParams->taskId,
			select: $select,
		);

		if ($task === null)
		{
			return null;
		}

		$modifiers = [
			fn (): array => $this->prepareFlow($taskParams, $task),
			fn (): array => $this->prepareGroup($taskParams, $task),
			fn (): array => $this->prepareCrmItems($taskParams, $task),
			fn (): array => $this->prepareFavorite($taskParams, $task),
			fn (): array => $this->preparePin($taskParams, $task),
			fn (): array => $this->prepareGroupPin($taskParams, $task),
			fn (): array => $this->prepareMute($taskParams, $task),
			fn (): array => $this->prepareRights($taskParams, $task),
			fn (): array => $this->prepareLink($taskParams, $task),
			fn (): array => $this->prepareArchiveLink($task),
		];

		$data = [];
		foreach ($modifiers as $modifier)
		{
			$data = [...$data, ...$modifier()];
		}

		$task = $task->cloneWith($data);

		if (FormV2Feature::isOn('miniform') && !FormV2Feature::isOn())
		{
			return $task;
		}

		if ($task->chatId === null)
		{
			$updatedTask = $this->egressController->createChatForExistingTask($task);

			$this->chatRepository->save(
				chatId: $updatedTask->chatId,
				taskId: $task->getId(),
			);

			return $updatedTask;
		}

		return $task;
	}

	private function prepareFlow(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->flow || !$taskParams->checkFlowAccess || !$task->flow)
		{
			return [];
		}

		$controller = $this->controllerFactory->create(Type::Flow, $taskParams->userId);
		if ($controller?->checkByItemId(FlowAction::READ->value, $task->flow->getId()))
		{
			return [];
		}

		// no allowed data for flow
		return ['flow' => null];
	}

	private function prepareGroup(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->group || !$taskParams->checkGroupAccess || !$task->group)
		{
			return [];
		}

		$controller = $this->controllerFactory->create(Type::Group, $taskParams->userId);
		if ($controller?->checkByItemId(GroupDictionary::VIEW, $task->group->getId()))
		{
			return [];
		}

		// only allowed data
		$group = new Group(
			id: $task->group->getId(),
			name: $task->group->name,
			image: $task->group->image,
			type: $task->group->type,
		);

		return ['group' => $group->toArray()];
	}

	private function prepareCrmItems(TaskParams $taskParams, Task $task): array
	{
		if (!$task->crmItemIds || !$taskParams->checkCrmAccess)
		{
			return [];
		}

		$crmItemIds = $this->crmAccessService->filterCrmItemsWithAccess($task->crmItemIds, $taskParams->userId);

		return ['crmItemIds' => $crmItemIds];
	}

	private function prepareFavorite(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->favorite || empty($task->inFavorite))
		{
			return [];
		}

		$inFavorite = in_array($taskParams->userId, (array)$task->inFavorite, true) ? [$taskParams->userId] : [];

		return ['inFavorite' => $inFavorite];
	}

	private function preparePin(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inPin))
		{
			return [];
		}

		$inPin = in_array($taskParams->userId, (array)$task->inPin, true) ? [$taskParams->userId] : [];

		return ['inPin' => $inPin];
	}

	private function prepareGroupPin(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inGroupPin))
		{
			return [];
		}

		$inGroupPin = in_array($taskParams->userId, (array)$task->inGroupPin, true) ? [$taskParams->userId] : [];

		return ['inGroupPin' => $inGroupPin];
	}

	private function prepareMute(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inMute))
		{
			return [];
		}

		$inMute = in_array($taskParams->userId, (array)$task->inMute, true) ? [$taskParams->userId] : [];

		return ['inMute' => $inMute];
	}

	private function prepareRights(TaskParams $taskParams, Task $task): array
	{
		$rights = $this->taskRightService->get(
			\Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary::TASK_ACTIONS,
			$task->getId(),
			$taskParams->userId,
		);

		return ['rights' => $rights];
	}

	private function prepareLink(TaskParams $taskParams, Task $task): array
	{
		$link = $this->linkService->get($task, $taskParams->userId);

		return ['link' => $link];
	}

	private function prepareArchiveLink(Task $task): array
	{
		$archiveLink = $this->diskArchiveLinkService->get($task->getId());

		return ['archiveLink' => $archiveLink];
	}
}
