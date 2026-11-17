<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation;

use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;

final class Request
{
	public function __construct(
		public readonly string $requestId,
		public readonly MessengerConfirmationStatus $status,
		public readonly int $expiresAt,
		public readonly int $resendAvailableAt,
		public readonly int $pollAfter,
		public readonly ?array $connectionConfig = null,
	)
	{
	}

	public function getTtl(int $timestamp): int
	{
		return max(0, $this->expiresAt - $timestamp);
	}
}
