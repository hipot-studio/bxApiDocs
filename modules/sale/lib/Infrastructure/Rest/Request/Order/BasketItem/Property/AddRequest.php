<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem\Property;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Input of `sale.order.basketitem.property.add`. The position is addressed beside `fields`, since
 * `basketItemId` is read only in the data type; the service reports a missing one together with other input errors.
 */
class AddRequest extends Request
{
	/**
	 * Deliberately `mixed`: the platform assigns scalars in weak mode, so a typed property would cast `true` to 1.
	 * The raw value is read by {@see \Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport::readPositive()}.
	 */
	public mixed $basketItemId = null;

	public FieldsStructure $fields;
}
