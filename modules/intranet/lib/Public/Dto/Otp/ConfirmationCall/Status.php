<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall;

use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Intranet\Public\Enum\Otp\ConfirmationCallStatus;
use Bitrix\Main\ArgumentException;

final class Status
{
	public function __construct(
		public readonly ConfirmationCallStatus $status,
		public readonly ?string $reason,
	)
	{
		if (
			$reason !== null
			&& !in_array(
				$reason,
				[
					ConfirmationCallChannelInterface::ERROR_UNAVAILABLE,
					ConfirmationCallChannelInterface::ERROR_INVALID_REQUEST,
					ConfirmationCallChannelInterface::ERROR_TRANSPORT_FAILURE,
				],
				true,
			)
		)
		{
			throw new ArgumentException('Status reason must be a safe error category.', 'reason');
		}
	}
}
