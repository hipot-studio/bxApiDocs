<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Send;

use Bitrix\Im\V2\Message;

class ImportantUsers
{
	public function __construct(
		public readonly array $userIds = [],
		public readonly bool $immutable = true,
	) {}

	public static function createByMessage(Message $message): self
	{
		if ($message->isImportant() || $message->isSystem())
		{
			return new self([], false);
		}

		return new self(array_values($message->getMentionedUserIds()), false);
	}
}
