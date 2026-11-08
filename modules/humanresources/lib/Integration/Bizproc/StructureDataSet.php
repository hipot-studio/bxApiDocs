<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

/**
 * Result of a structure read: the nodes, the links person to node to role and the people of those links.
 * Deduplication touches $userIds only, so a person listed in two nodes keeps a link row per node.
 */
final class StructureDataSet
{
	/**
	 * @param list<StructureNodeRow> $nodes
	 * @param list<StructureMemberRow> $members
	 * @param list<int> $userIds
	 * @param string|null $reason a StructureDataCollector REASON_* code when the result is short
	 */
	public function __construct(
		public readonly array $nodes = [],
		public readonly array $members = [],
		public readonly array $userIds = [],
		public readonly bool $truncated = false,
		public readonly ?string $reason = null,
	)
	{
	}
}
