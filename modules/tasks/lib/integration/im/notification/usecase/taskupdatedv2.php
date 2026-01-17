<?php

namespace Bitrix\Tasks\Integration\IM\Notification\UseCase;

use Bitrix\Tasks\Integration\IM\Notification;
use Bitrix\Tasks\Internals\Notification\Message;
use Bitrix\Tasks\Internals\Notification\UseCase\TaskUpdatedV2Action;
use Bitrix\Tasks\Internals\TaskObject;

class TaskUpdatedV2
{
	public function getNotification(Message $message): ?Notification
	{
		$metadata = $message->getMetaData();
		$task = $metadata->getTask();
		$userRepository = $metadata->getUserRepository();
		$action = $metadata->getParams()['update_action'] ?? null;

		if ($task === null || $userRepository === null || $action === null)
		{
			return null;
		}

		return match ($action) {
			TaskUpdatedV2Action::RemoveUser => $this->makeRemoveUserNotification($message, $task),
			TaskUpdatedV2Action::AddAsAuditor => $this->makeAddAsAuditorNotification($message, $task),
			TaskUpdatedV2Action::AddAsAccomplice => $this->makeAddAsAccompliceNotification($message, $task),
			default => null,
		};
	}

	private function makeRemoveUserNotification(Message $message, TaskObject $task): Notification
	{
		return $this->makeNotify(
			$message,
			$task,
			'TASKS_IM_NOTIFY_REMOVE_FROM_TASK_MESSAGE',
			'TASKS_IM_NOTIFY_REMOVE_FROM_TASK_SUBJECT'
		);
	}

	private function makeAddAsAuditorNotification(Message $message, TaskObject $task): Notification
	{
		return $this->makeNotify(
			$message,
			$task,
			'TASKS_IM_NOTIFY_ADD_IN_TASK_AS_AUDITOR_MESSAGE',
			'TASKS_IM_NOTIFY_ADD_IN_TASK_AS_AUDITOR_SUBJECT'
		);
	}

	private function makeAddAsAccompliceNotification(Message $message, TaskObject $task): Notification
	{
		return $this->makeNotify(
			$message,
			$task,
			'TASKS_IM_NOTIFY_ADD_IN_TASK_AS_ACCOMPLICE_MESSAGE',
			'TASKS_IM_NOTIFY_ADD_IN_TASK_AS_ACCOMPLICE_SUBJECT'
		);
	}

	private function makeNotify(Message $message, TaskObject $task, string $messageLocKey, string $subjectLocKey): Notification
	{
		$titleTemplate = $this->makeTitleTemplate($message, $task);

		$notification = new Notification($messageLocKey, $message);
		$notification->addTemplate($titleTemplate);

		$subjectNotification = new Notification($subjectLocKey, $message);
		$subjectNotification->addTemplate($titleTemplate);
		$subjectInstantNotification = new Notification\Task\InstantNotification($subjectNotification);

		$notification->setParams([
			'PARAMS' => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subjectInstantNotification->getMessage(),
				],
			],
		]);

		return $notification;
	}

	private function makeTitleTemplate(Message $message, TaskObject $task): Notification\Template
	{
		return new Notification\Template(
			'#TASK_TITLE#',
			(new Notification\Task\Title($task))->getFormatted($message->getRecepient()->getLang())
		);
	}
}
