<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Item;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;

/**
 * The item is named by its identifier alone: which type it is of belongs to the route and is never
 * read from the body.
 */
class SetPrimaryContactRequest extends AbstractItemRequest
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	#[NotEmpty]
	#[PositiveNumber]
	public int $contactId;
}
