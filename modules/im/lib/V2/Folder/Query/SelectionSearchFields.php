<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Folder\Query;

/**
 * Column names a {@see SelectionSearchFilter} writes a selection into. The translator
 * itself is table-agnostic: the caller names the columns of the table it queries.
 */
final class SelectionSearchFields
{
	public function __construct(
		public readonly string $typeField,
		public readonly string $entityTypeField,
		public readonly string $chatIdField,
		public readonly string $parentField,
	) {}
}
