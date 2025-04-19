<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Event;

use Bitrix\Main\EventResult;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\Task\Event\View\OnTaskFirstViewEvent;

final class ViewTaskEventListener extends AbstractEventListener
{
	public function onTaskView(OnTaskFirstViewEvent $event): EventResult
	{
		$eventResult = new EventResult(EventResult::SUCCESS);

		$taskId = $event->getTaskId();
		$userId = $event->getUserId();

		$task = TaskRegistry::getInstance()->getObject($taskId);
		if ($task === null)
		{
			return $eventResult;
		}

		if ($task->getResponsibleId() !== $userId)
		{
			return $eventResult;
		}

		$this->deleteJobs($taskId);

		return new EventResult(EventResult::SUCCESS);
	}
}