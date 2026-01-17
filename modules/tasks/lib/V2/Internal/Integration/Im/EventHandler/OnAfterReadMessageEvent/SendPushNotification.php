<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\EventHandler\OnAfterReadMessageEvent;

use Bitrix\Im\V2\Chat\ExternalChat\Event\AfterReadMessagesEvent;
use Bitrix\Tasks\Internals\Counter\Role;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Pull\Push;
use Bitrix\Tasks\V2\Internal\Logger;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\TaskReadRepositoryInterface;

class SendPushNotification
{
	public function __construct
	(
		private readonly TaskReadRepositoryInterface $tasksRepository,
		private readonly TaskMemberRepositoryInterface $memberRepository,
		private readonly Push\Service $push,
		private readonly Logger $logger,
	) {
	}

	public function __invoke(AfterReadMessagesEvent $event): void
	{
		if (!$this->push->isEnabled())
		{
			return;
		}

		try
		{
			$task = $this->tasksRepository->getById((int)$event->getChat()->getEntityId(), select: new Select(members: true));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
			return;
		}

		if (null === $task)
		{
			return;
		}

		try
		{
			/** @var ?User $member */
			$member = $this->memberRepository->get($task->getId())->filter(fn(User $member): bool => $member->getId() === (int) $event->getReaderId())->getFirstEntity();

			$this->push->send($task->getMemberIds(), $this->getPayload($event, $task, $member));
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}
	private function getPayload(AfterReadMessagesEvent $event, Task $task, ?User $member): Push\TaskViewed
	{
		$payload = new Push\TaskViewed(
			taskId: $task->getId(),
			userId: $event->getReaderId(),
			groupId: $task->group?->getId(),
		);

		if ($member !== null)
		{
			$payload->role = Role::getRoleId($member->role);
		}

		return $payload;
	}
}