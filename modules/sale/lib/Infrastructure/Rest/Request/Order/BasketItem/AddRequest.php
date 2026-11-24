<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order\BasketItem;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Input of `sale.order.basketitem.add`.
 *
 * The stock create input carries nothing but `fields`, and the order cannot travel inside them: the
 * data type keeps `orderId` read only, so that the requirement of an address does not leak into the
 * basket nested in `sale.order.add`, where the order is not addressed at all. So the address stands
 * beside the fields, in a request of its own - which also means idempotency is not switched on by
 * the request type and the action declares it explicitly.
 *
 * The address is optional to the platform and required by the service. That is deliberate: refused
 * here it would be the only thing the caller hears about, while the service adds it to everything
 * else the payload got wrong and answers with the whole set at once.
 */
class AddRequest extends Request
{
	/**
	 * The address arrives unread: the platform assigns it in weak mode (`Request::create()` lives in
	 * a file without strict_types), so any declared scalar type is a cast, and `true` would reach the
	 * service as the address of order 1. Kept `mixed`, the raw value reaches the single reading of
	 * the layer, {@see \Bitrix\Sale\Infrastructure\Rest\Support\IdentifierSupport::readPositive()},
	 * which refuses everything that merely casts into a number.
	 */
	public mixed $orderId = null;

	public FieldsStructure $fields;
}
