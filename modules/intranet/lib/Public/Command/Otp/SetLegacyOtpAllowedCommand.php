<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Public\Command\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class SetLegacyOtpAllowedCommand extends AbstractCommand
{
	public function __construct(
		public readonly User $user,
		public readonly bool $allowed,
	) {
	}

	protected function execute(): Result
	{
		return (new SetLegacyOtpAllowedHandler())($this);
	}
}
