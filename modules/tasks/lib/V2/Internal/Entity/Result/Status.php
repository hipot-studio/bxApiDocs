<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Result;

enum Status: string
{
	case Open = 'open';
	case Closed = 'closed';

	public function getRaw(): int
	{
		return match ($this)
		{
			self::Open => \Bitrix\Tasks\Internals\Task\Result\ResultTable::STATUS_OPENED,
			self::Closed => \Bitrix\Tasks\Internals\Task\Result\ResultTable::STATUS_CLOSED,
		};
	}

	public static function fromRaw(int $value): self
	{
		return match ($value) {
			\Bitrix\Tasks\Internals\Task\Result\ResultTable::STATUS_OPENED => self::Open,
			\Bitrix\Tasks\Internals\Task\Result\ResultTable::STATUS_CLOSED => self::Closed,
		};
	}
}
