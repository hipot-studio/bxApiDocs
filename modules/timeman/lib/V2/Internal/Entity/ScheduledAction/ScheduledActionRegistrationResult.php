<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\ScheduledAction;

final class ScheduledActionRegistrationResult
{
	public function __construct(
		public readonly int $actionId,
		public readonly bool $created,
	)
	{
	}

	public function isCreated(): bool
	{
		return $this->created;
	}
}
