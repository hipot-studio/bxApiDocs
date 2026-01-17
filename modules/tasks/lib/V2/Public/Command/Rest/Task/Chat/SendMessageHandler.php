<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Rest\Task\Chat;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\NotifyCustomMessage;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;
use Bitrix\Tasks\V2\Internal\Result\Result;

class SendMessageHandler
{
	public function __construct(
		private readonly MessageSenderInterface $messageSender,
	)
	{
	}

	public function __invoke(SendMessageCommand $command): Result
	{
		$task = new Task($command->taskId);

		$author = new User($command->userId);

		$notification = new NotifyCustomMessage($author, $command->message->text);

		return $this->messageSender->sendMessage($task, $notification);
	}
}
