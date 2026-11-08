<?php

namespace Bitrix\Iblock\Infrastructure\Rest\Request\Element;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest as BaseListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class ListRequest extends BaseListRequest
{
	#[FilterRequired(['iblockId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
