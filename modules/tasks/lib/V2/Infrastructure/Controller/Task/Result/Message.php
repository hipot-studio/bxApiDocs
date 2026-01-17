<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Task\Result;

use Bitrix\Tasks\V2\Internal\Result\Result;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Command\Task\Result\AddResultFromMessageCommand;
use Bitrix\Tasks\V2\Public\Provider\TaskResultProvider;
use Bitrix\Tasks\V2\Internal\Integration\Im;

class Message extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Task.Result.Message.add
	 */
	public function addAction(
		#[Im\Access\Result\Permission\Add]
		Im\Entity\Message $message,
		TaskResultProvider $taskResultProvider,
	): ?Entity\Result
	{
		$commandResult = (new AddResultFromMessageCommand(
			userId: $this->userId,
			messageId: (int)$message->id,
		))->run();

		/** @var Result $commandResult */
		if (!$commandResult->isSuccess())
		{
			$this->addErrors($commandResult->getErrors());

			return null;
		}

		return $taskResultProvider->getResultById($commandResult->getId(), $this->userId);
	}
}
