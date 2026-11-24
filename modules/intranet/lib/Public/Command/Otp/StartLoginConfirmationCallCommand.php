<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Public\Service\Otp\ConfirmationCallService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class StartLoginConfirmationCallCommand extends AbstractCommand
{
	public function __construct(
		private readonly ?ConfirmationCallService $service = null,
	)
	{
	}

	protected function execute(): Result
	{
		return ($this->service ?? new ConfirmationCallService())->startLogin();
	}
}
