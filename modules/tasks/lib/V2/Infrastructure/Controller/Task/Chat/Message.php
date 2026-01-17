<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Chat;

use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Access\Task\Permission\Read;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im;
use Bitrix\Tasks\V2\Public\Command\Task\Chat\SendMessageCommand;

class Message extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Chat.Message.send
	 */
	public function sendAction(
		#[Read]
		Entity\Task $task,
		Im\Entity\Message $message,
	): ?bool
	{
		$result = (new SendMessageCommand(
			taskId: $task->getId(),
			userId: $this->userId,
			message: $message,
		))->run();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return true;
	}
}
