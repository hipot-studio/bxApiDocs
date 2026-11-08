<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;

final class ForwardMessageCollection extends MessageCollection
{
	public function checkAccess(?int $userId = null): Result
	{
		foreach ($this as $message)
		{
			if (!$message->checkAccess($userId)->isSuccess())
			{
				return (new Result())->addError(new MessageError(MessageError::NOT_FOUND));
			}
		}

		return new Result();
	}
}
