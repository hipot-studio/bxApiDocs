<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment\Request;

final class SetThreadFlagRequest
{
	public function __construct(
		public readonly string $threadId,
		public readonly string $userId,
		public readonly bool $value,
	)
	{
	}
}
