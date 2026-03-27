<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Admin;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class SetRightsCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SetRightsHandler())($this);
	}
}
