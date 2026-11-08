<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Request\Element;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Interaction\Request\GetRequest as BaseGetRequest;

class GetRequest extends BaseGetRequest
{
	#[PositiveNumber]
	public int $iblock;
}
