<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;
use Bitrix\Tasks\V2\Internal\Event\Task\OnAccessRequestedEvent;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Exception\Task\AccessRequestException;
use Bitrix\Tasks\V2\Internal\Repository\TaskAccessRequestRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;

class AccessRequestService
{
	public function __construct(
		private readonly TaskRepositoryInterface $taskRepository,
		private readonly TaskAccessRequestRepositoryInterface $taskAccessRequestRepository,
		private readonly UserRepositoryInterface $userRepository,
		private readonly EventDispatcher $eventDispatcher,
		private readonly TaskAccessService $taskAccessService,
	)
	{

	}

	public function requestAccess(int $userId, int $taskId): AccessRequest
	{
		if (!$this->taskRepository->isExists($taskId))
		{
			throw new AccessRequestException(Loc::getMessage('TASKS_ACCESS_REQUEST_SERVICE_TASK_NOT_FOUND'));
		}

		$user = $this->userRepository->getByIds([$userId])->findOneById($userId);
		if ($user === null)
		{
			throw new AccessRequestException(Loc::getMessage('TASKS_ACCESS_REQUEST_SERVICE_USER_NOT_FOUND'));
		}

		if ($this->taskAccessService->canRead($userId, $taskId))
		{
			throw new AccessRequestException(Loc::getMessage('TASKS_ACCESS_REQUEST_SERVICE_TASK_CAN_READ'));
		}

		$accessRequest = new AccessRequest(
			taskId: $taskId,
			userId: $userId,
			createdDateTs: time(),
		);

		try
		{
			$this->taskAccessRequestRepository->add($accessRequest);
		}
		catch (DuplicateEntryException)
		{
			throw new AccessRequestException(Loc::getMessage('TASKS_ACCESS_REQUEST_SERVICE_TASK_DUPLICATE_REQUEST'));
		}

		$this->eventDispatcher::dispatch(new OnAccessRequestedEvent(
			task: new Task(id: $taskId),
			triggeredBy: $user,
		));

		return $accessRequest;
	}

	public function clearAccessRequests(int $lifeTimeTs): void
	{
		$this->taskAccessRequestRepository->clearByTime(time() - $lifeTimeTs);
	}

	public function clearAccessRequestsByTaskId(int $taskId): void
	{
		$this->taskAccessRequestRepository->clearByTaskId($taskId);
	}

	public function clearAccessRequestsByUserId(int $userId): void
	{
		$this->taskAccessRequestRepository->clearByUserId($userId);
	}
}
