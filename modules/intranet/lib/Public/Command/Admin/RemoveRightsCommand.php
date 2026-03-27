<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Admin;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class RemoveRightsCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $currentUserId,
	)
	{
	}

	protected function execute(): Result
	{
		return (new RemoveRightsHandler())($this);
	}
}
