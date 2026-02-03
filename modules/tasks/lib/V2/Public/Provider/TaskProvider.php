<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Flow\Access\FlowAction;
use Bitrix\Tasks\Scrum;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskRightService;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Access\Service\CrmAccessService;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Service\DiskArchiveLinkService;
use Bitrix\Tasks\V2\Internal\Integration\Mail\Service\EmailAccessService;
use Bitrix\Tasks\V2\Internal\Repository\ChatRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressInterface;
use Bitrix\Tasks\V2\Internal\Service\Link\LinkService;
use Bitrix\Tasks\V2\Internal\Service\Task\ViewService;
use Bitrix\Tasks\V2\Public\Provider\Params\TaskParams;

class TaskProvider
{
	protected readonly TaskReadRepositoryInterface $taskRepository;
	protected readonly ChatRepositoryInterface $chatRepository;
	protected readonly EgressInterface $egressController;
	protected readonly ControllerFactoryInterface $controllerFactory;
	protected readonly CrmAccessService $crmAccessService;
	protected readonly LinkService $linkService;
	protected readonly TaskRightService $taskRightService;
	protected readonly DiskArchiveLinkService $diskArchiveLinkService;
	protected readonly ViewService $viewService;
	protected readonly EmailAccessService $emailAccessService;
	protected readonly Scrum\Service\TaskService $scrumTaskService;

	public function __construct()
	{
		$this->taskRepository = Container::getInstance()->get(TaskReadRepositoryInterface::class);
		$this->chatRepository = Container::getInstance()->get(ChatRepositoryInterface::class);
		$this->egressController = Container::getInstance()->get(EgressInterface::class);
		$this->controllerFactory = Container::getInstance()->get(ControllerFactoryInterface::class);
		$this->crmAccessService = Container::getInstance()->get(CrmAccessService::class);
		$this->linkService = Container::getInstance()->get(LinkService::class);
		$this->taskRightService = Container::getInstance()->get(TaskRightService::class);
		$this->diskArchiveLinkService = Container::getInstance()->get(DiskArchiveLinkService::class);
		$this->viewService = Container::getInstance()->get(ViewService::class);
		$this->emailAccessService = Container::getInstance()->get(EmailAccessService::class);
		$this->scrumTaskService = new Scrum\Service\TaskService(); //todo
	}

	public function get(TaskParams $taskParams): ?Task
	{
		return $this->getById($taskParams);
	}

	protected function getById(TaskParams $taskParams): ?Task
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
			group: $taskParams->group || $taskParams->stage,
			flow: $taskParams->flow,
			stage: $taskParams->stage,
			members: $taskParams->members,
			checkLists: $taskParams->checkLists,
			crm: $taskParams->crm,
			tags: $taskParams->tags,
			subTasks: $taskParams->subTasks,
			relatedTasks: $taskParams->relatedTasks,
			gantt: $taskParams->gantt,
			placements: $taskParams->placements,
			containsCommentFiles: $taskParams->containsCommentFiles,
			favorite: $taskParams->favorite,
			options: $taskParams->options,
			parameters: $taskParams->parameters,
			results: $taskParams->results,
			reminders: $taskParams->reminders,
			userFields: $taskParams->userFields,
			email: $taskParams->email,
			scenarios: $taskParams->scenarios,
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
			fn (): array => $this->prepareStage($taskParams, $task),
			fn (): array => $this->prepareEmail($taskParams, $task),
		];

		$data = [];
		foreach ($modifiers as $modifier)
		{
			$data = [...$data, ...$modifier()];
		}

		$task = $task->cloneWith($data);

		if ($taskParams->view)
		{
			$this->viewService->set(new Task\View(
				taskId: $task->getId(),
				userId: $taskParams->userId,
				isRealView: true,
			));
		}

		if (FormV2Feature::isOn('miniform') && !FormV2Feature::isOn('', $task->group?->id))
		{
			return $task;
		}

		if ($task->chatId === null)
		{
			return $this->egressController->createChatForExistingTask($task);
		}

		return $task;
	}

	protected function prepareFlow(TaskParams $taskParams, Task $task): array
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

	protected function prepareGroup(TaskParams $taskParams, Task $task): array
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

	protected function prepareCrmItems(TaskParams $taskParams, Task $task): array
	{
		if (!$task->crmItemIds || !$taskParams->checkCrmAccess)
		{
			return [];
		}

		$crmItemIds = $this->crmAccessService->filterCrmItemsWithAccess($task->crmItemIds, $taskParams->userId);

		return ['crmItemIds' => $crmItemIds];
	}

	protected function prepareFavorite(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->favorite || empty($task->inFavorite))
		{
			return [];
		}

		$inFavorite = in_array($taskParams->userId, (array)$task->inFavorite, true) ? [$taskParams->userId] : [];

		return ['inFavorite' => $inFavorite];
	}

	protected function preparePin(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inPin))
		{
			return [];
		}

		$inPin = in_array($taskParams->userId, (array)$task->inPin, true) ? [$taskParams->userId] : [];

		return ['inPin' => $inPin];
	}

	protected function prepareGroupPin(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inGroupPin))
		{
			return [];
		}

		$inGroupPin = in_array($taskParams->userId, (array)$task->inGroupPin, true) ? [$taskParams->userId] : [];

		return ['inGroupPin' => $inGroupPin];
	}

	protected function prepareMute(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->options || empty($task->inMute))
		{
			return [];
		}

		$inMute = in_array($taskParams->userId, (array)$task->inMute, true) ? [$taskParams->userId] : [];

		return ['inMute' => $inMute];
	}

	protected function prepareRights(TaskParams $taskParams, Task $task): array
	{
		$rights = $this->taskRightService->get(
			\Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary::TASK_ACTIONS,
			$task->getId(),
			$taskParams->userId,
		);

		return ['rights' => $rights];
	}

	protected function prepareLink(TaskParams $taskParams, Task $task): array
	{
		$link = $this->linkService->get($task, $taskParams->userId);

		return ['link' => $link];
	}

	protected function prepareArchiveLink(Task $task): array
	{
		$archiveLink = $this->diskArchiveLinkService->getByTaskId($task->getId());

		return ['archiveLink' => $archiveLink];
	}

	protected function prepareStage(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->stage || $task->group?->getId() <= 0 || !$task->group?->isScrum())
		{
			return [];
		}

		if ($this->scrumTaskService->isInBacklog($task->getId(), $task->group->getId()))
		{
			return ['stage' => ['id' => 0]];
		}

		return [];
	}

	protected function prepareEmail(TaskParams $taskParams, Task $task): array
	{
		if (!$taskParams->email || $task->email?->getId() <= 0)
		{
			return [];
		}

		[$canRead, $access] = $this->emailAccessService->canRead($task->email, $taskParams->userId);
		if (!$canRead)
		{
			return ['email' => ['id' => 0]];
		}

		if (!is_array($access))
		{
			return [];
		}

		$email = $this->emailAccessService->getWithToken($task->email, $access, $taskParams->userId);

		return ['email' => $email];
	}
}
