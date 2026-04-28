<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Result;

class UnreadResult extends Result
{
	public function __construct(
		public readonly int $userId,
		public readonly int $chatId,
		public readonly int $counter,
		public readonly array $unreadList,
	)
	{
		parent::__construct();
	}

	public static function error(Error $error): static
	{
		return (new static(0, 0, 0, []))->addError($error);
	}

	public static function empty(int $userId, int $chatId, int $counter): static
	{
		return (new static($userId, $chatId, $counter, []));
	}
}
