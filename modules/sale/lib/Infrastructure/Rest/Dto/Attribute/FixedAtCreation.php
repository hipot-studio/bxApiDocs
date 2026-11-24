<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Attribute;

/**
 * A field an element of the order takes only while it is being created.
 *
 * The platform checks editability in the group of the whole call, so a field meant for creation
 * only cannot be declared editable in `add` alone: an element created inside a change would be
 * refused it. The field is declared editable in both groups, and this mark tells the layer what an
 * element which already exists does with it:
 * - by default it may not carry the field and is refused
 *   ({@see \Bitrix\Sale\Infrastructure\Rest\Support\CreationFieldsValidator});
 * - ignored on change, the field is dropped from it without a word
 *   ({@see \Bitrix\Sale\Infrastructure\Rest\Support\EditableFieldsFilter}), so that an element read
 *   and sent back, or a repeated exchange, still passes.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class FixedAtCreation
{
	public function __construct(public readonly bool $ignoredOnChange = false)
	{
	}
}
