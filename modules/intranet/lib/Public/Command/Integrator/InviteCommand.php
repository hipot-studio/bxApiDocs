<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Integrator;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class InviteCommand extends AbstractCommand
{
	public function __construct(
		public readonly string $integratorEmail,
		public readonly array $partnerData = [],
	)
	{
	}

	protected function execute(): Result
	{
		return (new InviteHandler())($this);
	}
}