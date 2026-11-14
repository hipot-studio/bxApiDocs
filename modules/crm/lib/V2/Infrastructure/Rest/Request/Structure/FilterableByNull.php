<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

/**
 * Marks a DTO whose nullable filterable fields are compared with null in {@see ItemListFilterStructure}:
 * `["field", null]`, `["field", "=", null]` and `["field", "!=", null]` ask whether the field holds no value.
 * Any other operator with null is refused, and a field that always has a value is checked as before.
 *
 * The method reading the DTO answers such a comparison, so the mark is put only where the storage does.
 */
interface FilterableByNull
{
}
