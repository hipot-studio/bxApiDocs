<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Request\Record;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class ListRequest extends \Bitrix\Rest\V3\Interaction\Request\ListRequest
{
	#[FilterRequired(['userId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
