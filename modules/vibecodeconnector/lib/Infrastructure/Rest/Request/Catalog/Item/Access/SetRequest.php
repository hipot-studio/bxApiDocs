<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Access;

use Bitrix\Main\Validation\Rule;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;
use Bitrix\Rest\V3\Interaction\Request\Request;

class SetRequest extends Request
{
	#[Rule\PositiveNumber]
	public int $catalogItemId;

	#[ElementsType(typeEnum: Type::String)]
	public array $accessCodes = [];
}
