<?php

namespace Bitrix\Rest\V3\Realisation\Request\Field\Custom;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;

class ListRequest extends \Bitrix\Rest\V3\Interaction\Request\ListRequest
{
	#[FilterRequired(['entityId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}