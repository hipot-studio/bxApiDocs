<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\Property;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * Input of `sale.order.basketitem.property.list`: the filter must name the basket position.
 * Both attributes are needed: {@see FilterRequired} checks a filter that arrived, and only
 * {@see NotEmpty} catches a missing filter, which the platform skips before the composition check.
 */
class ListRequest extends \Bitrix\Rest\V3\Interaction\Request\ListRequest
{
	#[FilterRequired(['basketItemId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
