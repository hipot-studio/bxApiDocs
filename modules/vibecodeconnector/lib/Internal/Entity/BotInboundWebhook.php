<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity;

final class BotInboundWebhook
{
	public function __construct(
		public readonly int $passwordId,
		public readonly int $userId,
		public readonly string $password,
		public readonly bool $isActive,
	)
	{
	}
}
