<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat;

final class AppChatRetryPolicy
{
	private const DELAY_BY_ATTEMPT = [60, 300, 1800, 7200, 21600];

	public function forAttempt(int $attemptCount): int
	{
		$index = max(0, min($attemptCount - 1, count(self::DELAY_BY_ATTEMPT) - 1));

		return self::DELAY_BY_ATTEMPT[$index];
	}
}
