<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Input of `sale.order.import`.
 *
 * The exchange has no identifier of its own to carry: the order is addressed by the `xmlId` inside
 * the fields, and whether that names an existing order or a new one is the resolution's answer.
 * The request is therefore its own type rather than a create or an update request, which also
 * means idempotency is not switched on by the request type and the action declares it explicitly.
 */
class ImportRequest extends Request
{
	public FieldsStructure $fields;
}
