<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall;

use Bitrix\Main\ArgumentException;

final class Attempt
{
	public function __construct(
		public readonly string $confirmationCallId,
		public readonly string $providerPhoneNumber,
		public readonly int $expiresAt,
		public readonly array $pullConfig,
	)
	{
		if (preg_match('/^[A-Za-z0-9_-]{20}$/D', $confirmationCallId) !== 1)
		{
			throw new ArgumentException(
				'Confirmation call id must be a 20-character Base64URL value.',
				'confirmationCallId',
			);
		}

		if (preg_match('/^\+[1-9][0-9]{1,14}$/D', $providerPhoneNumber) !== 1)
		{
			throw new ArgumentException(
				'Provider phone number must be a non-empty E.164 value.',
				'providerPhoneNumber',
			);
		}

		if ($expiresAt <= 0)
		{
			throw new ArgumentException('Expiration timestamp must be positive.', 'expiresAt');
		}

		if ($pullConfig === [])
		{
			throw new ArgumentException('Pull configuration must not be empty.', 'pullConfig');
		}
	}
}
