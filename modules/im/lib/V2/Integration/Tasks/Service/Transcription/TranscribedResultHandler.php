<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Integration\AI\TaskCreation\Status;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Integration\Tasks\Service\ResultService;
use Bitrix\Im\V2\Pull\Event\AutoTaskStatus;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;

class TranscribedResultHandler
{
	public function __construct(
		private readonly ResultService $resultService,
	)
	{
	}

	public function handle(string $resultData, Message $message, int $initiatorId): bool
	{
		if (empty($resultData) || !Loader::includeModule('tasks'))
		{
			return false;
		}

		$chat = $message->getChat();

		if ($chat->getEntityType() !== Chat::ENTITY_TYPE)
		{
			return false;
		}

		$taskId = (int)$chat->getEntityId();
		$messageId = (int)$message->getId();

		if ($initiatorId <= 0 || $taskId <= 0 || $messageId <= 0)
		{
			return false;
		}

		if (enum_exists('\Bitrix\Tasks\V2\Internal\Entity\Result\Type'))
		{
			$result = new Result(
				taskId: $taskId,
				text: $resultData,
				author: new User($initiatorId),
				type: Result\Type::Ai,
				messageId: $messageId,
			);
		}
		else
		{
			$result = new Result(
				taskId: $taskId,
				text: $resultData,
				author: new User($initiatorId),
				messageId: $messageId,
			);
		}

		try
		{
			$this->resultService->add($result, $initiatorId);
		}
		catch (SystemException)
		{
			return false;
		}

		// the terminal success is announced only once the result exists: every false of this method
		// must stay silent so that the caller owns the refusal and no contradictory pair is sent
		(new AutoTaskStatus($message, $initiatorId, Status::ResultCreationCompleted, true))->send();

		return true;
	}
}
