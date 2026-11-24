<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall;

use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationTerminalStatus;
use Bitrix\Main\Result;

final class ResponseResult extends Result
{
	public function __construct(
		private readonly Attempt|Status|PhoneConfirmationTerminalStatus $payload,
	)
	{
		parent::__construct();
	}

	public function getPayload(): Attempt|Status|PhoneConfirmationTerminalStatus
	{
		return $this->payload;
	}
}
