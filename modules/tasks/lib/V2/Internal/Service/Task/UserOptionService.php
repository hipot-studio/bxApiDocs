<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\TaskUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Entity\Task\UserOption;
use Bitrix\Tasks\V2\Internal\Event;
use Bitrix\Tasks\V2\Internal\EventDispatcher\EventDispatcher;
use Bitrix\Tasks\V2\Internal\Service\Counter;
use Bitrix\Tasks\V2\Internal\Service\EventService;
use Bitrix\Tasks\V2\Internal\Service\PushService;

class UserOptionService
{
	public function __construct(
		private readonly TaskUserOptionRepositoryInterface $userOptionRepository,
		private readonly PushService                       $pushService,
		private readonly Counter\Service                   $counterService,
		private readonly EventService                      $eventService,
		private readonly EventDispatcher                   $eventDispatcher,
	)
	{

	}

	public function pin(int $taskId, int $userId): void
	{
		$userOption = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::PINNED,
		);

		$this->add($userOption);
	}

	public function unpin(int $taskId, int $userId): void
	{
		$userOption = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::PINNED,
		);

		$this->delete($userOption);
	}

	public function pinInGroup(int $taskId, int $userId): void
	{
		$userOption = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::PINNED_IN_GROUP,
		);

		$this->add($userOption);
	}

	public function unpinInGroup(int $taskId, int $userId): void
	{
		$userOption = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::PINNED_IN_GROUP,
		);

		$this->delete($userOption);
	}

	public function mute(int $taskId, int $userId): void
	{
		$entity = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::MUTED,
		);

		$this->counterService->collect($entity->taskId);

		$this->add($entity);

		$this->counterService->send(new Counter\Command\AfterTaskMute(
			taskId: $entity->taskId,
			userId: $entity->userId,
			added: true,
		));

		$task = new Entity\Task(id: $taskId);
		$user = new Entity\User(id: $userId);

		$this->eventDispatcher->dispatch(new Event\Task\OnTaskMutedEvent($task, $user));
	}

	public function unmute(int $taskId, int $userId): void
	{
		$entity = new UserOption(
			userId: $userId,
			taskId: $taskId,
			code: Option::MUTED,
		);

		$this->counterService->collect($entity->taskId);

		$this->delete($entity);

		$this->counterService->send(new Counter\Command\AfterTaskMute(
			taskId: $entity->taskId,
			userId: $entity->userId,
			added: true,
		));

		$task = new Entity\Task(id: $taskId);
		$user = new Entity\User(id: $userId);

		$this->eventDispatcher->dispatch(new Event\Task\OnTaskUnmutedEvent($task, $user));
	}

	public function muteBatch(int $taskId, array $userIds): void
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		$userOptions = new Entity\Task\UserOptionCollection();
		foreach ($userIds as $userId)
		{
			$userOptions->add(new UserOption(
				userId: $userId,
				taskId: $taskId,
				code: Option::MUTED,
			));
		}

		$this->addBatch($userOptions);

		$this->counterService->collect($taskId);
		foreach ($userIds as $userId)
		{
			$this->counterService->send(new Counter\Command\AfterTaskMute(
				taskId: $taskId,
				userId: $userId,
				added: true,
			));

			$task = new Entity\Task(id: $taskId);
			$user = new Entity\User(id: $userId);
			$this->eventDispatcher->dispatch(new Event\Task\OnTaskMutedEvent($task, $user));
		}
	}

	public function deleteByTaskIdAndUserIds(int $taskId, array $userIds): void
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		$toDelete = new Entity\Task\UserOptionCollection();

		foreach ($userIds as $userId)
		{
			$toDelete->add(new UserOption(
				userId: $userId,
				taskId: $taskId,
			));
		}

		$this->deleteBatch($toDelete);
	}

	public function deleteOnUserRoleChanged(int $taskId, array $userIds): void
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		$toDelete = new Entity\Task\UserOptionCollection();

		foreach ($userIds as $userId)
		{
			$toDelete->add(new UserOption(
				userId: $userId,
				taskId: $taskId,
				code: Option::MUTED,
			));
		}

		$this->deleteBatch($toDelete);
	}

	private function add(UserOption $userOption): void
	{
		// Prevent re-sending events if the option is already set. Moved from \Bitrix\Tasks\Internals\UserOption::add
		if ($this->userOptionRepository->isSet($userOption->code, $userOption->taskId, $userOption->userId))
		{
			return;
		}

		$this->userOptionRepository->add($userOption);

		$this->pushService->addEventByParameters(
			recipients: UserCollection::mapFromIds([$userOption->userId]),
			command: PushCommand::USER_OPTION_UPDATED,
			parameters: [
				'TASK_ID' => $userOption->taskId,
				'USER_ID' => $userOption->userId,
				'OPTION' => $userOption->code,
				'ADDED' => true,
			]
		);

		$this->eventService->send(
			type: 'onTaskUserOptionChanged',
			parameters: [
				'taskId' => $userOption->taskId,
				'userId' => $userOption->userId,
				'option' => $userOption->code,
				'added' => true,
			]
		);
	}

	private function delete(UserOption $userOption): void
	{
		// Prevent re-sending events if the option is already deleted. Moved from \Bitrix\Tasks\Internals\UserOption::delete
		if (!$this->userOptionRepository->isSet($userOption->code, $userOption->taskId, $userOption->userId))
		{
			return;
		}

		$this->userOptionRepository->delete([$userOption->code], $userOption->taskId, $userOption->userId);

		$this->pushService->addEventByParameters(
			recipients: UserCollection::mapFromIds([$userOption->userId]),
			command: PushCommand::USER_OPTION_UPDATED,
			parameters: [
				'TASK_ID' => $userOption->taskId,
				'USER_ID' => $userOption->userId,
				'OPTION' => $userOption->code,
				'ADDED' => true,
			]
		);

		$this->eventService->send(
			type: 'onTaskUserOptionChanged',
			parameters: [
				'taskId' => $userOption->taskId,
				'userId' => $userOption->userId,
				'option' => $userOption->code,
				'added' => false,
			]
		);
	}

	private function addBatch(Entity\Task\UserOptionCollection $userOptions): void
	{
		if ($userOptions->isEmpty())
		{
			return;
		}

		$toAdd = new Entity\Task\UserOptionCollection();
		$userIds = [];

		foreach ($userOptions as $userOption)
		{
			if (!$this->userOptionRepository->isSet($userOption->code, $userOption->taskId, $userOption->userId))
			{
				$toAdd->add($userOption);
				$userIds[] = $userOption->userId;
			}
		}

		if ($toAdd->isEmpty())
		{
			return;
		}

		$this->userOptionRepository->addBatch($toAdd);

		/** @var UserOption $firstOption */
		$firstOption = $toAdd->getFirstEntity();

		$this->pushService->addEventByParameters(
			recipients: UserCollection::mapFromIds(array_unique($userIds)),
			command: PushCommand::USER_OPTION_UPDATED,
			parameters: [
				'TASK_ID' => $firstOption->taskId,
				'OPTION' => $firstOption->code,
				'ADDED' => true,
			],
		);

		/** @var UserOption $userOption */
		foreach ($toAdd as $userOption)
		{
			$this->eventService->send(
				type: 'onTaskUserOptionChanged',
				parameters: [
					'taskId' => $userOption->taskId,
					'userId' => $userOption->userId,
					'option' => $userOption->code,
					'added' => true,
				],
			);
		}
	}

	private function deleteBatch(Entity\Task\UserOptionCollection $userOptions): void
	{
		if ($userOptions->isEmpty())
		{
			return;
		}

		$toDelete = new Entity\Task\UserOptionCollection();
		$userIds = [];
		$codes = [];
		foreach ($userOptions as $userOption)
		{
			if (
				$userOption->code !== null
				&& !$this->userOptionRepository->isSet($userOption->code, $userOption->taskId, $userOption->userId)
			)
			{
				continue;
			}

			if ($userOption->code !== null)
			{
				$codes[] = $userOption->code;
			}

			$userIds[] = $userOption->userId;

			$toDelete->add($userOption);
		}

		if ($toDelete->isEmpty())
		{
			return;
		}

		/** @var UserOption $firstOption */
		$firstOption = $toDelete->getFirstEntity();

		$this->userOptionRepository->deleteBatch(
			taskId: $firstOption->taskId,
			userIds: array_unique($userIds),
			codes: array_unique($codes),
		);

		$this->pushService->addEventByParameters(
			recipients: UserCollection::mapFromIds(array_unique($userIds)),
			command: PushCommand::USER_OPTION_UPDATED,
			parameters: [
				'TASK_ID' => $firstOption->taskId,
				'OPTION' => $firstOption->code,
				'ADDED' => true,
			],
		);

		foreach ($toDelete as $userOption)
		{
			$this->eventService->send(
				type: 'onTaskUserOptionChanged',
				parameters: [
					'taskId' => $userOption->taskId,
					'userId' => $userOption->userId,
					'option' => $userOption->code,
					'added' => false,
				],
			);
		}
	}
}
