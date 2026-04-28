<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Notification;

use Bitrix\Im\V2\Error;
use Bitrix\Im\V2\Result;

class ReadResult extends Result
{
	public function __construct(
		public readonly int $userId,
		public readonly int $chatId,
		public readonly int $counter,
		public readonly array $readList,
	)
	{
		$this->setResult(['COUNTER' => $this->counter, 'VIEWED_MESSAGES' => $this->readList]);
		parent::__construct();
	}

	public static function error(Error $error): static
	{
		return (new static(0, 0, 0, []))->addError($error);
	}

	public static function fromMultiReadResult(MultiReadResult $result, int $userId, int $chatId): self
	{
		$counter = $result->counters->getByUserId($userId);
		$readList = $result->readList[$userId] ?? [];

		return new self($userId, $chatId, $counter, $readList);
	}
}
