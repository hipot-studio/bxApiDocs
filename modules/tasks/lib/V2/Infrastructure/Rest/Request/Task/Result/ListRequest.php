<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Request\Task\Result;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class ListRequest extends \Bitrix\Rest\V3\Interaction\Request\ListRequest
{
	#[FilterRequired(['taskId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}