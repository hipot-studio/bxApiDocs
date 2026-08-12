<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;

final class CreateCatalogItemCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		#[Rule\PositiveNumber]
		public readonly int $userId,
		public readonly string $title,
		public readonly string $type,
		public readonly string $accessType,
		public readonly ?string $description = null,
		public readonly ?string $editUrl = null,
		public readonly ?string $viewUrl = null,
		public readonly ?int $chatId = null,
		public readonly ?string $externalId = null,
		public readonly ?string $iss = null,
		public readonly ?string $iconUrl = null,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		$result->setData([
			'item' => (new CreateCatalogItemCommandHandler())($this),
		]);

		return $result;
	}
}
