<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One CRM object an activity is bound to, as {@see ActivityDto::$bindings} answers it.
 *
 * The pair repeats {@see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\Common\ItemIdentifierDto} field for
 * field, which cannot be reused here because it is a contract of a write: it carries
 * {@see \Bitrix\Rest\V3\Attribute\Editable} and {@see \Bitrix\Rest\V3\Attribute\Required}, while a binding of
 * an activity is read only.
 *
 * An owner is not restricted to the item types of CRM - an order and an invoice are bound the same way - so
 * `entityTypeId` is answered as the number it is.
 */
final class ActivityBindingDto extends Dto
{
	public int $entityTypeId;

	public int $entityId;
}
