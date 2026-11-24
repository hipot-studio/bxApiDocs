<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\AttemptResult;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\Status;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\StatusResult;
use Bitrix\Intranet\Public\Enum\Otp\ConfirmationCallStatus;
use Bitrix\Main\Error;

final class NullConfirmationCallChannel implements ConfirmationCallChannelInterface
{
	public function isAvailable(int $userId, string $scenario): bool
	{
		return false;
	}

	public function start(string $phoneE164, int $userId, string $scenario): AttemptResult
	{
		$result = new AttemptResult();
		$result->addError(
			new Error(
				'Confirmation call is unavailable.',
				self::ERROR_UNAVAILABLE,
			),
		);

		return $result;
	}

	public function getStatus(string $confirmationCallId, int $userId, string $scenario): StatusResult
	{
		return new StatusResult(
			new Status(
				ConfirmationCallStatus::Unavailable,
				null,
			),
		);
	}
}
