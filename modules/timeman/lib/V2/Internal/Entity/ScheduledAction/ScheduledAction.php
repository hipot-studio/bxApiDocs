<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\ScheduledAction;

final class ScheduledAction
{
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly int $userId,
		public readonly int $executeTime,
		public readonly ScheduledActionStatus $status,
	)
	{
	}
}
