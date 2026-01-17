<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\EventHandler\Task\OnPlanChanged;

use Bitrix\Tasks\V2\Internal\Event\Task\OnPlanChangedEvent;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\AbstractNotify;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyEndPlanChanged;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyEndPlanDeleted;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyPlanChanged;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyPlanDeleted;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyStartPlanChanged;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyStartPlanDeleted;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSender;
use Bitrix\Tasks\V2\Internal\Logger;

class NotifyChat
{
	public function __construct(
		private readonly MessageSender $sender,
		private readonly Logger $logger,
	)
	{
	}

	public function __invoke(OnPlanChangedEvent $event): void
	{
		$notification = null;
		$startChanged = $event->task->startPlanTs !== $event->taskBefore->startPlanTs;
		$endChanged = $event->task->endPlanTs !== $event->taskBefore->endPlanTs;

		if ($startChanged && $endChanged)
		{
			$notification = $this->getNotificationForPlanDeleted($event);
		}
		elseif ($startChanged)
		{
			$notification = $this->getNotificationForStartChanged($event);
		}
		elseif ($endChanged)
		{
			$notification = $this->getNotificationForEndChanged($event);
		}

		if ($notification === null)
		{
			return;
		}

		try
		{
			$this->sender->sendMessage($event->task, $notification);
		}
		catch (\Throwable $e)
		{
			$this->logger->logError($e);
		}
	}

	private function getNotificationForPlanDeleted($event): AbstractNotify
	{
		if ($event->task->startPlanTs === null && $event->task->endPlanTs === null)
		{
			return new NotifyPlanDeleted(
				task: $event->task,
				triggeredBy: $event->triggeredBy,
			);
		}
		return new NotifyPlanChanged(
			task: $event->task,
			triggeredBy: $event->triggeredBy,
		);
	}

	private function getNotificationForStartChanged($event): AbstractNotify
	{
		if ($event->task->startPlanTs === null)
		{
			return new NotifyStartPlanDeleted(
				task: $event->task,
				triggeredBy: $event->triggeredBy,
			);
		}

		return new NotifyStartPlanChanged(
			task: $event->task,
			triggeredBy: $event->triggeredBy,
		);
	}

	private function getNotificationForEndChanged($event): AbstractNotify
	{
		if ($event->task->endPlanTs === null)
		{
			return new NotifyEndPlanDeleted(
				task: $event->task,
				triggeredBy: $event->triggeredBy,
			);
		}

		return new NotifyEndPlanChanged(
			task: $event->task,
			triggeredBy: $event->triggeredBy,
		);
	}
}
