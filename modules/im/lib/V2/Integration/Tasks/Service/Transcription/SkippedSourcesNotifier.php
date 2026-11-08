<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\Tasks\Service\MessageSender;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyCustomMessage;

/**
 * Tells the task participants that part of the selected audio or video was never transcribed and
 * therefore took no part in filling the task fields. The phrase belongs to im, so the notification
 * is NotifyCustomMessage, which carries an already built string instead of a tasks message code.
 */
class SkippedSourcesNotifier
{
	public function __construct(
		private readonly MessageSender $messageSender,
	)
	{
	}

	public function notify(Task $task, int $authorId): void
	{
		$text = (string)Loc::getMessage('IM_AI_TASK_SKIPPED_SOURCES_NOTICE');
		if ($text === '')
		{
			return;
		}

		$notification = new NotifyCustomMessage(triggeredBy: new User($authorId), message: $text);

		$this->messageSender->sendMessage($task, $notification);
	}
}
