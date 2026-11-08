<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Custom\Enum;

use Bitrix\Rest\V3\Interaction\Request\ListRequest as BaseListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

final class ListRequest extends BaseListRequest
{
	#[FilterRequired(['fieldId'])]
	public ?FilterStructure $filter = null;
}
