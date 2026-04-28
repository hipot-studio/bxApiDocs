<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\Partner;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class DeleteCommand extends AbstractCommand
{
	public function __construct(
		public bool $fromCheckout = false,
	)
	{
	}

	protected function execute(): Result
	{
		return (new DeleteHandler())($this);
	}
}
