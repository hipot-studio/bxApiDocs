<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Rest\V3\Structure\Filtering\Attribute\FilterRequired;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

/**
 * Input of `sale.order.basketitem.list`.
 *
 * The stock list input with the parent named in the filter: a collection of positions exists only
 * inside one order, so the address of that order is not optional. Both attributes are needed and
 * neither replaces the other: {@see FilterRequired} answers for the composition of a filter that
 * arrived, and only {@see NotEmpty} answers for a request that carries no filter at all - the
 * platform skips an absent property before it reaches the composition check
 * (`Rest\V3\Interaction\Request\Request::create()`).
 */
class ListRequest extends \Bitrix\Rest\V3\Interaction\Request\ListRequest
{
	#[FilterRequired(['orderId'])]
	#[NotEmpty]
	public ?FilterStructure $filter = null;
}
