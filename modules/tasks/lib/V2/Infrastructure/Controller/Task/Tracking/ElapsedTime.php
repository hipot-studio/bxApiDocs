<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Tracking;

use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission;
use Bitrix\Tasks\V2\Internal\Access\Task\Tracking;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\AddElapsedTimeCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\UpdateElapsedTimeCommand;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\DeleteElapsedTimeCommand;
use Bitrix\Tasks\V2\Public\Provider\Params\UserParams;
use Bitrix\Tasks\V2\Public\Provider\TaskElapsedTimeProvider;
use Bitrix\Tasks\V2\Public\Provider\UserProvider;

class ElapsedTime extends BaseController
{
	use AccessControllerTrait;

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.ElapsedTime.add
	 */
	public function addAction(
		#[Permission\Read]
		#[Tracking\Permission\ElapsedTime]
		Entity\Task $task,
		TaskElapsedTimeProvider $elapsedTimeProvider,
	): ?Entity\EntityInterface
	{
		$cloneProps = [
			'userId' => $this->userId,
			'taskId' => $task->getId(),
		];

		if ($task->elapsedTime->createdAtTs === null)
		{
			$cloneProps['createdAtTs'] = time();
		}

		$result = (new AddElapsedTimeCommand(
			elapsedTime: $task->elapsedTime->cloneWith($cloneProps),
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $elapsedTimeProvider->getById($result->getId(), $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.ElapsedTime.update
	 */
	public function updateAction(
		#[Tracking\Elapsed\Permission\Update]
		Entity\Task\ElapsedTime $elapsedTime,
		TaskElapsedTimeProvider $elapsedTimeProvider,
	): ?Entity\EntityInterface
	{
		$result = (new UpdateElapsedTimeCommand(
			elapsedTime: $elapsedTime,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $elapsedTimeProvider->getById((int)$elapsedTime->getId(), $this->userId);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.ElapsedTime.delete
	 */
	public function deleteAction(
		#[Tracking\Elapsed\Permission\Delete]
		Entity\Task\ElapsedTime $elapsedTimeId,
	): ?bool
	{
		$result = (new DeleteElapsedTimeCommand(
			elapsedTime: $elapsedTimeId,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.ElapsedTime.list
	 */
	public function listAction(
		#[Permission\Read]
		Entity\Task $task,
		PageNavigation $pageNavigation,
		TaskElapsedTimeProvider $elapsedTimeProvider,
	): ?Entity\Task\ElapsedTimeCollection
	{
		return $elapsedTimeProvider->getList(
			taskId: $task->getId(),
			userId: $this->userId,
			pager: Pager::buildFromPageNavigation($pageNavigation),
		);
	}

	/**
	 * @ajaxAction tasks.V2.Task.Tracking.ElapsedTime.listParticipants
	 */
	public function listParticipantsAction(
		#[Permission\Read]
		Entity\Task $task,
		TaskElapsedTimeProvider $elapsedTimeProvider,
		UserProvider $userProvider,
	): array
	{
		$participantsContribution = $elapsedTimeProvider->getParticipantsContribution(taskId: $task->getId());

		$userCollection = $userProvider->getByIds(
			new UserParams(
				userId: $this->userId,
				targetUserIds: array_keys($participantsContribution),
			),
		);

		return [
			'users' => $userCollection,
			'contribution' => $participantsContribution,
		];
	}
}
