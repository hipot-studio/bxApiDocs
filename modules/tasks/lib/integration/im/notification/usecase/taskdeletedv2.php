<?php

namespace Bitrix\Tasks\Integration\IM\Notification\UseCase;

use Bitrix\Tasks\Integration\IM\Notification;
use Bitrix\Tasks\Internals\Notification\Message;

class TaskDeletedV2
{
	public function getNotification(Message $message): ?Notification
	{
		$task = $message->getMetaData()->getTask();

		if ($task === null)
		{
			return null;
		}

		$titleTemplate = new Notification\Template(
			'#TASK_TITLE#',
			(new Notification\Task\Title($task))->getFormatted($message->getRecepient()->getLang())
		);

		$notification = new Notification('TASKS_IM_NOTIFY_TASK_DELETED_MESSAGE', $message);
		$notification->addTemplate($titleTemplate);

		$subjectNotification = new Notification('TASKS_IM_NOTIFY_TASK_DELETED_SUBJECT', $message);
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
}