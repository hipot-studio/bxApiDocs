<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Intranet\Public\Service\Otp\ConfirmationCallService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class StartSetupConfirmationCallCommand extends AbstractCommand
{
	public function __construct(
		public readonly User $user,
		public readonly Phone $phoneNumber,
		private readonly ?ConfirmationCallService $service = null,
	)
	{
	}

	protected function execute(): Result
	{
		return ($this->service ?? new ConfirmationCallService())->startSetup($this->user, $this->phoneNumber);
	}
}
