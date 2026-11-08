<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Favorite;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class FavoriteCatalogItemCommand extends Main\Command\AbstractCommand
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
		(new FavoriteCatalogItemCommandHandler())($this);

		return new Main\Result();
	}
}
