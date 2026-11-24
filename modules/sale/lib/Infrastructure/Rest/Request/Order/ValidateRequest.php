<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Request\Order;

use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\FieldsStructure;

/**
 * Input of `sale.order.validate`.
 *
 * The same input the create and the update take, with the identifier optional: without it the
 * assembly is checked as a creation, with it as a change of that order. The request is its own type
 * rather than a create or an update one, and that is deliberate: the platform switches idempotency
 * on by the request type, and an operation that saves no order has no result to replay.
 */
class ValidateRequest extends Request
{
	public ?string $id = null;

	public FieldsStructure $fields;
}
