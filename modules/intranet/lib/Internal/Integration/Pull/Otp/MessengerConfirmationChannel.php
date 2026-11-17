<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Pull\Otp;

final class MessengerConfirmationChannel
{
	public function __construct(
		public readonly string $tag,
		public readonly array $connectionConfig,
	)
	{
	}
}
