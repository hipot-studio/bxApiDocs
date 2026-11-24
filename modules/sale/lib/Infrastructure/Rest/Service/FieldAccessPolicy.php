<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Service;

use Bitrix\Main\Result;
use Bitrix\Rest\V3\Access\FieldAccessContext;
use Bitrix\Rest\V3\Access\FieldAccessProvider;

/**
 * Who may read the list of fields of a resource of the module.
 *
 * The rule is the one of the resource itself and comes from the single place holding the access
 * rules of this layer ({@see AccessSupport}): the schema of the fields describes the very data the
 * resource serves, so the right to see the description is the right to see the data. V1 closed the
 * catalog field list the same way (`Sale\Controller\BasketItem::checkReadPermissionEntity()`).
 *
 * Reading the schema - `list` and `get` - needs the right to read an order. Everything else needs
 * the right to write: the field methods of a resource with user fields change the fields themselves,
 * and no resource of the module is going to publish that on a reading right.
 *
 * The filter builds the provider by the class name alone, so the dependency has a default rather
 * than a place in the call.
 */
final class FieldAccessPolicy implements FieldAccessProvider
{
	private const READING_ACTIONS = ['list', 'get'];

	public function __construct(
		private readonly AccessSupport $accessSupport = new AccessSupport(),
	)
	{
	}

	public function decide(FieldAccessContext $context): Result
	{
		return in_array($context->action, self::READING_ACTIONS, true)
			? $this->accessSupport->ensureOrderRead()
			: $this->accessSupport->ensureOrderModify()
		;
	}
}
