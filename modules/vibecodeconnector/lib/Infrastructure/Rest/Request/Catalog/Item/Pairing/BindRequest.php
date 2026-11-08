<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Request\Catalog\Item\Pairing;

use Bitrix\Main\Validation\Rule;
use Bitrix\Rest\V3\Interaction\Request\Request;

class BindRequest extends Request
{
	#[Rule\PositiveNumber]
	public int $catalogItemId;
}
