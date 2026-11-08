<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One folder of the path of a place.
 *
 * A node stands for what the user sees rather than for the folder that stores the object: where a
 * shared folder is reached through a link of the user, the node carries the identifier and the name
 * of that link, because those are what the client keeps in its own tree.
 *
 * A node exists only for a folder the user may read. The path is cut at the boundary of access
 * instead of being padded with unnamed nodes, so this class has no state for "a folder whose name is
 * withheld" - such a folder never becomes a node at all.
 */
final class PathNodeDto extends Dto
{
	public int $id;

	public string $name;
}
