<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Integration\Tasks\Service\ResultService;
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

	public function handle(string $resultData, Message $message): bool
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

		$authorId = $message->getAuthorId();
		$taskId = (int)$chat->getEntityId();

		if ($authorId <= 0 || $taskId <= 0)
		{
			return false;
		}

		$result = new Result(
			taskId: $taskId,
			text: $resultData,
			author: new User($authorId),
		);

		try
		{
			$this->resultService->add($result, $authorId);
		}
		catch (SystemException)
		{
			return false;
		}

		return true;
	}
}
