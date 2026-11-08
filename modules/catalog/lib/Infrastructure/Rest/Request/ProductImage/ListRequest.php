<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Request\ProductImage;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Interaction\Request\ListRequest as BaseListRequest;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

class ListRequest extends BaseListRequest
{
	#[FilterRequired(['productId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
