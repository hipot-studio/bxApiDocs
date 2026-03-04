<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper;

use Bitrix\Tasks\Control\Exception\TaskNotExistsException;
use Bitrix\Tasks\Internals\UserOption\Option;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;
use Bitrix\Tasks\V2\Internal\LoggerInterface;
use Bitrix\Tasks\V2\Internal\Repository;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

class DefaultMapper implements NotificationMapperInterface, CounterMapperInterface
{
	public function __construct(
		private readonly Repository\TaskRepositoryInterface $repository,
		private readonly Repository\TaskUserOptionRepositoryInterface $optionRepository,
	)
	{
	}

	public function __invoke(RecipientsResolver $context): void
	{
		$taskId = (int)$context->task->id;
		$taskWithMembers = $this->repository->getById($taskId);

		if ($taskWithMembers === null)
		{
			$exception = new TaskNotExistsException('Task ' . $taskId . ' not found');
			Container::getInstance()
				->getLogger()
				->logWarning(
					[
						'message' => $exception->getMessage(),
						'trace' => $exception->getTraceAsString(),
					],
					LoggerInterface::TASKS_NOT_EXISTS_MARKER
				);

			return;
		}

		$context->taskWithMembers = $taskWithMembers;
		$context->inMuteMembers =
			$this->optionRepository
				->get($taskId)
				?->filter(fn (Entity\Task\UserOption $option): bool => $option->code === Option::MUTED)
				->getUserIdList()
		;

		$recipients = new Entity\UserCollection();

		foreach ($context->notification->getRecipients() as $role)
		{
			$record = match ($role)
			{
				Role::Creator => $context->taskWithMembers->creator,
				Role::Responsible => $context->taskWithMembers->responsible,
				Role::Accomplice => $context->taskWithMembers->accomplices,
				Role::Auditor => $context->taskWithMembers->auditors,
				default => null,
			};

			if ($record instanceof Entity\UserCollection)
			{
				$recipients->merge($record);
			}
			elseif ($record instanceof Entity\User)
			{
				$recipients->add($record);
			}
		}

		$context->recipients->merge($recipients);
	}
}
