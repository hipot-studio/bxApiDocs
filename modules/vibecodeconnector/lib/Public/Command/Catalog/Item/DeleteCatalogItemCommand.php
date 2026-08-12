<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class DeleteCatalogItemCommand extends Main\Command\AbstractCommand
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
		$result = new Main\Result();
		$result->setData([
			'item' => (new DeleteCatalogItemCommandHandler())($this),
		]);

		return $result;
	}
}
