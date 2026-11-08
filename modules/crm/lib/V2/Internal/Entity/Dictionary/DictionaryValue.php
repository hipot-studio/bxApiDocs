<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Dictionary;

/**
 * A value of a dictionary as the storage boundary hands it upwards.
 *
 * `id` is the identifier a client addresses the value by, and it is stable: no operation of this domain
 * writes it after the value is created. The internal record key of the row is not here at all - it does
 * not leave the repository, and nothing above it may address a value by anything but the pair
 * (dictionary, `id`).
 *
 * `isSystem` marks a value that ships with the product. It is read-only in every operation: the name and
 * the sort order of such a value change like any other, but the mark itself does not.
 *
 * @internal
 */
final readonly class DictionaryValue
{
	public function __construct(
		public string $id,
		public string $name,
		public int $sort,
		public bool $isSystem,
	)
	{
	}
}
