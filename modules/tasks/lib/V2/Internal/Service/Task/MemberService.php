<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Application;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Event\Task\OnTaskMutedUserSyncEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Trait\CastTrait;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\UpdateTaskService;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationLock;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationRegistry;
use Bitrix\Tasks\V2\Internal\Service\Task\Mutation\TaskMutationScope;

class MemberService
{
	use CastTrait;

	public function __construct(
		private readonly UpdateTaskService $updateService,
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly EventDispatcher $eventDispatcher,
		private readonly TaskMutationRegistry $mutationRegistry,
		private readonly TaskMutationLock $mutationLock,
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

		return $this->mutate($taskId, function () use ($taskId, $creatorId, $responsibleId, $config): Task {
			$task = $this->taskRepository->getById($taskId);
			if ($task === null)
			{
				throw new TaskNotExistsException();
			}

			return $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'creator' => $this->castMember($creatorId),
				'responsible' => $this->castMember($responsibleId),
			]), $config);
		});
	}

	public function delegate(
		int $taskId,
		int $responsibleId,
		UpdateConfig $config
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $responsibleId, $config): Task {
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

			$updatedTask = $this->updateService->update(Task::mapFromArray($data), $config);

			if ($updatedTask->allowsTimeTracking && $config->getUserId() !== $responsibleId)
			{
				Container::getInstance()->getTimeManagementService()->stopTimer(
					userId: $config->getUserId(),
					taskId: $taskId,
				);
			}

			return $updatedTask;
		});
	}

	public function setAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $auditorIds, $config): Task {
			$result = $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'auditors' => $this->castMembers($auditorIds),
			]), $config);

			$this->eventDispatcher->dispatch(new OnTaskMutedUserSyncEvent($result));

			return $result;
		});
	}

	public function addAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $auditorIds, $config): Task {
			$task = $this->taskRepository->getById($taskId);
			if ($task === null)
			{
				throw new TaskNotExistsException();
			}

			$auditors = array_merge($auditorIds, (array)$task->auditors?->getIdList());
			Collection::normalizeArrayValuesByInt($auditors, false);
			$result = $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'auditors' => $this->castMembers($auditors),
			]), $config);

			$this->eventDispatcher->dispatch(new OnTaskMutedUserSyncEvent($result));

			return $result;
		});
	}

	public function deleteAuditors(
		int $taskId,
		array $auditorIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $auditorIds, $config): Task {
			$task = $this->taskRepository->getById($taskId);
			if ($task === null)
			{
				throw new TaskNotExistsException();
			}

			$auditors = array_diff((array)$task->auditors?->getIdList(), $auditorIds);
			Collection::normalizeArrayValuesByInt($auditors, false);

			return $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'auditors' => $this->castMembers($auditors),
			]), $config);
		});
	}

	public function setAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, fn (): Task => $this->updateService->update(Task::mapFromArray([
			'id' => $taskId,
			'accomplices' => $this->castMembers($accompliceIds),
		]), $config));
	}

	public function addAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $accompliceIds, $config): Task {
			$task = $this->taskRepository->getById($taskId);
			if ($task === null)
			{
				throw new TaskNotExistsException();
			}

			$accomplices = array_merge($accompliceIds, (array)$task->accomplices?->getIdList());
			Collection::normalizeArrayValuesByInt($accomplices, false);

			return $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'accomplices' => $this->castMembers($accomplices),
			]), $config);
		});
	}

	public function deleteAccomplices(
		int $taskId,
		array $accompliceIds,
		UpdateConfig $config,
	): Task
	{
		return $this->mutate($taskId, function () use ($taskId, $accompliceIds, $config): Task {
			$task = $this->taskRepository->getById($taskId);
			if ($task === null)
			{
				throw new TaskNotExistsException();
			}

			$accomplices = array_diff((array)$task->accomplices?->getIdList(), $accompliceIds);
			Collection::normalizeArrayValuesByInt($accomplices, false);

			return $this->updateService->update(Task::mapFromArray([
				'id' => $taskId,
				'accomplices' => $this->castMembers($accomplices),
			]), $config);
		});
	}

	private function mutate(int $taskId, callable $operation): Task
	{
		return $this->mutationRegistry->run(
			Application::getConnection(),
			function (TaskMutationScope $scope) use ($taskId, $operation): Task {
				$this->mutationLock->acquire($taskId, $scope);
				$this->taskRepository->invalidate($taskId);

				return $operation();
			},
		);
	}
}
