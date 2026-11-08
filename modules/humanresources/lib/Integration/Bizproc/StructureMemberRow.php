<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * One link person to node to role. A person holding two roles in one node, or the same role in two
 * nodes, gives a row per link: the block of links is never collapsed.
 */
final class StructureMemberRow
{
	public function __construct(
		public readonly int $userId,
		public readonly int $nodeId,
		public readonly string $nodeName,
		public readonly NodeMemberRole $role,
	)
	{
	}
}
