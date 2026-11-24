<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall;

use Bitrix\Main\Result;

final class AttemptResult extends Result
{
	public function __construct(private readonly ?Attempt $attempt = null)
	{
		parent::__construct();
	}

	public function getAttempt(): ?Attempt
	{
		return $this->attempt;
	}
}
