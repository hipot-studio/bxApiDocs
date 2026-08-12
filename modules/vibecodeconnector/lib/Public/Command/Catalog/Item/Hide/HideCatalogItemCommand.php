<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Hide;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class HideCatalogItemCommand extends Main\Command\AbstractCommand
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
		(new HideCatalogItemCommandHandler())($this);

		return new Main\Result();
	}
}
