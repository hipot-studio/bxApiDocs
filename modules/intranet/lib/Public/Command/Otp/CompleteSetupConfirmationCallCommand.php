<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Service\Otp\ConfirmationCallService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class CompleteSetupConfirmationCallCommand extends AbstractCommand
{
	public function __construct(
		public readonly User $user,
		public readonly string $confirmationCallId,
		private readonly ?ConfirmationCallService $service = null,
	)
	{
	}

	protected function execute(): Result
	{
		return ($this->service ?? new ConfirmationCallService())->completeSetup($this->user, $this->confirmationCallId);
	}
}
