<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Message\Send\SendResult;
use Bitrix\Tasks\V2\Internal\Result\Result;

class SendResultAdapter
{
	public function transform(SendResult $sendMessageResult): Result
	{
		$result = new Result();
		$messageId = $sendMessageResult->getMessageId();
		if ($messageId !== null)
		{
			$result->setId($messageId);
		}

		$result->addErrors($sendMessageResult->getErrors());

		return $result;
	}
}