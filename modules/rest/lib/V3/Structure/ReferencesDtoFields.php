<?php

namespace Bitrix\Rest\V3\Structure;

/**
 * A structure that can tell which DTO fields the raw client input names before the structure is created.
 * The extraction follows the grammar of create() and rejects a shape it cannot read
 * with the exception create() would throw.
 */
interface ReferencesDtoFields
{
	/**
	 * @return list<string>
	 */
	public static function getReferencedFields(mixed $value): array;
}
