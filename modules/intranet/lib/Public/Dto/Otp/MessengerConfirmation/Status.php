<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation;

use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;

final class Status
{
	public function __construct(
		public readonly string $requestId,
		public readonly MessengerConfirmationStatus $status,
		public readonly int $expiresAt,
		public readonly int $pollAfter,
		public readonly ?string $code = null,
	)
	{
	}
}
