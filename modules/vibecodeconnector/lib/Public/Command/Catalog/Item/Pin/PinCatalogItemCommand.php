<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pin;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class PinCatalogItemCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		#[Rule\PositiveNumber]
		public readonly int $userId,
		#[Rule\PositiveNumber]
		public readonly int $catalogItemId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		(new PinCatalogItemCommandHandler())($this);

		return new Main\Result();
	}
}
