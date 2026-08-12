<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class UpdateCatalogItemCommand extends Main\Command\AbstractCommand
{
	/**
	 * @param array<string, mixed> $fields
	 */
	public function __construct(
		#[Rule\PositiveNumber]
		public readonly int $userId,
		#[Rule\PositiveNumber]
		public readonly int $catalogItemId,
		public readonly array $fields,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		$result->setData([
			'item' => (new UpdateCatalogItemCommandHandler())($this),
		]);

		return $result;
	}
}
