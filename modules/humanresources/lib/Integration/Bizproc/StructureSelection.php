<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeEntityType;

/**
 * What to read from the structure: nodes to start from, how to walk from them and where to stop.
 */
final class StructureSelection
{
	/**
	 * @param list<int> $sourceNodeIds nodes the walk starts at; All ignores them
	 * @param list<NodeEntityType> $nodeTypes kinds of node the walk answers for
	 * @param int $limit the largest number of rows a read may give back
	 */
	public function __construct(
		public readonly array $sourceNodeIds,
		public readonly StructureSelectionMode $mode,
		public readonly array $nodeTypes,
		public readonly int $limit,
	)
	{
	}
}
