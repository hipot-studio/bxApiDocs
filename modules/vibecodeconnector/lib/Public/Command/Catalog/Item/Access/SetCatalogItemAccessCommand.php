<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Access;

use Bitrix\Main;
use Bitrix\Main\Validation\Rule;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;

final class SetCatalogItemAccessCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		#[Rule\PositiveNumber]
		public readonly int $userId,
		#[Rule\PositiveNumber]
		public readonly int $catalogItemId,
		#[ElementsType(typeEnum: Type::String)]
		public readonly array $accessCodes,
	)
	{
	}

	protected function execute(): Main\Result
	{
		(new SetCatalogItemAccessCommandHandler())($this);

		return new Main\Result();
	}
}
