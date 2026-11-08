<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Update\UpdateService;

final class MessageEditor
{
	public function setText(int $messageId, int $contextUserId, string $text): void
	{
		if ($messageId <= 0)
		{
			return;
		}

		$message = new Message($messageId);
		if ($message->getId() === null || $message->getMessage() === $text)
		{
			return;
		}
		$message->setContextUser($contextUserId);
		$result = (new UpdateService($message))
			->setContext($message->getContext())
			->update(['MESSAGE' => $text])
		;
		if (!$result->isSuccess())
		{
			\AddMessage2Log(
				'Message ' . $messageId . ' was not given its new text, '
					. implode('; ', $result->getErrorMessages()),
				'vibecodeconnector',
			);
		}
	}
}
