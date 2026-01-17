<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\RecipientsResolver;
use Bitrix\Tasks\V2\Internal\Repository;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;

class DefaultMapper implements NotificationMapperInterface, CounterMapperInterface
{
	public function __construct(
		private readonly Repository\TaskReadRepositoryInterface $repository,
	)
	{
	}

	public function __invoke(RecipientsResolver $context): void
	{
		$taskWithMembers = $this->repository->getById($context->task->id, new Repository\Task\Select(members: true));

		$recipients = new Entity\UserCollection();

		foreach ($context->notification->getRecipients() as $role)
		{
			$record = match ($role) {
				Role::Creator => $taskWithMembers->creator,
				Role::Responsible => $taskWithMembers->responsible,
				Role::Accomplice => $taskWithMembers->accomplices,
				Role::Auditor => $taskWithMembers->auditors,
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
