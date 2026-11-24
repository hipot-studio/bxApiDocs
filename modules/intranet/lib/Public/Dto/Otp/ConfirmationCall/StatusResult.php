<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall;

use Bitrix\Main\Result;

final class StatusResult extends Result
{
	public function __construct(private readonly ?Status $status = null)
	{
		parent::__construct();
	}

	public function getStatus(): ?Status
	{
		return $this->status;
	}
}
