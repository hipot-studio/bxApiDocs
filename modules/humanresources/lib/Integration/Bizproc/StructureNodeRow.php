<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * One structure node of a read. The level starts at 1 for the root of the structure, the path holds the
 * names from that root down to the node, each prefixed with a slash.
 */
final class StructureNodeRow
{
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly NodeEntityType $type,
		public readonly bool $active,
		public readonly ?int $parentId,
		public readonly int $level,
		public readonly string $path,
	)
	{
	}
}
