<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedUserSyncEvent;
use Bitrix\Tasks\V2\Internal\Event\Task\OnCreatorUpdatedEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;

class MemberService
{
	use CastTrait;

	public function __construct(
		private readonly UpdateTaskService $updateService,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly EventDispatcher $eventDispatcher,
	)
	{

	}

	/**
	 * @throws TaskNotExistsException
	 */
	public function getMemberIds(int $taskId): array
	{
		$task = $this->taskRepository->getById($taskId);

		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		return $task->getMemberIds();
	}

	public function updateCreator(
		int $taskId,
		int $creatorId,
		int $responsibleId,
		UpdateConfig $config,
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$previousCreatorId = $task->creator?->id ?? 0;

		$task = Task::mapFromArray([
			'id' => $taskId,
			'creator' => $this->castMember($creatorId),
			'responsible' => $this->castMember($responsibleId),
		]);

		return $this->updateService->update($task, $config);
	}

	public function delegate(
		int $taskId,
		int $responsibleId,
		UpdateConfig $config
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$data = [
			'id' => $taskId,
			'responsible' => $this->castMember($responsibleId),
			'status' => Task\Status::Pending->value,
		];

		$members = $task->getMembers([Role::Creator, Role::Accomplice, Role::Auditor]);
		if (!$members->findOneById((int)$task->responsible?->id) && !$task->group?->isScrum())
		{
			$auditors = $task->auditors ?? new UserCollection();
			$auditors->add(User::mapFromId((int)$task->responsible->id));
			$data['auditors'] = $auditors->toArray();
		}

		$task = Task::mapFromArray($data);

		return $this->updateService->update($task, $config);
	}

	public function setAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		$task = Task::mapFromArray([
			'id' => $taskId,
			'auditors' => $this->castMembers($auditorIds),
		]);

		$result = $this->updateService->update($task, $config);

		$this->eventDispatcher->dispatch(new OnTaskMutedUserSyncEvent($result));

		return $result;
	}

	public function addAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$current = (array)$task->auditors?->getIdList();
		$auditors = array_merge($auditorIds, $current);

		Collection::normalizeArrayValuesByInt($auditors, false);

		$task = Task::mapFromArray([
			'id' => $taskId,
			'auditors' => $this->castMembers($auditors),
		]);

		$result = $this->updateService->update($task, $config);

		$this->eventDispatcher->dispatch(new OnTaskMutedUserSyncEvent($result));

		return $result;
	}

	public function deleteAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$current = (array)$task->auditors?->getIdList();
		$auditors = array_diff($current, $auditorIds);

		Collection::normalizeArrayValuesByInt($auditors, false);

		$task = Task::mapFromArray([
			'id' => $taskId,
			'auditors' => $this->castMembers($auditors),
		]);

		return $this->updateService->update($task, $config);
	}

	public function setAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		$task = Task::mapFromArray([
			'id' => $taskId,
			'accomplices' => $this->castMembers($accompliceIds),
		]);

		return $this->updateService->update($task, $config);
	}

	public function addAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$current = (array)$task->accomplices?->getIdList();
		$accomplices = array_merge($accompliceIds, $current);

		Collection::normalizeArrayValuesByInt($accomplices, false);

		$task = Task::mapFromArray([
			'id' => $taskId,
			'accomplices' => $this->castMembers($accomplices),
		]);

		return $this->updateService->update($task, $config);
	}

	public function deleteAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		$task = $this->taskRepository->getById($taskId);
		if ($task === null)
		{
			throw new TaskNotExistsException();
		}

		$current = (array)$task->accomplices?->getIdList();
		$accomplices = array_diff($current, $accompliceIds);

		Collection::normalizeArrayValuesByInt($accomplices, false);

		$task = Task::mapFromArray([
			'id' => $taskId,
			'accomplices' => $this->castMembers($accomplices),
		]);

		return $this->updateService->update($task, $config);
	}
}
