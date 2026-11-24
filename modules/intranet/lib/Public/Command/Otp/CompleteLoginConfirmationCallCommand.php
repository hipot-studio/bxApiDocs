<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Public\Service\Otp\ConfirmationCallService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class CompleteLoginConfirmationCallCommand extends AbstractCommand
{
	public function __construct(
		public readonly string $confirmationCallId,
		public readonly string $rememberDevice,
		public readonly string $captchaWord,
		public readonly string $captchaSid,
		private readonly ?ConfirmationCallService $service = null,
	)
	{
	}

	protected function execute(): Result
	{
		return ($this->service ?? new ConfirmationCallService())->completeLogin(
			$this->confirmationCallId,
			$this->rememberDevice,
			$this->captchaWord,
			$this->captchaSid,
		);
	}
}
