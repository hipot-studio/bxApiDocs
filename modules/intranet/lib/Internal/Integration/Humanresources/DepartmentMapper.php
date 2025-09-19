<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Item\Node;

class DepartmentMapper
{
	public function createDepartmentFromNode(Node $node): \Bitrix\Intranet\Entity\Department
	{
		return new \Bitrix\Intranet\Entity\Department(
			name: $node->name,
			id: $node->id,
			parentId: $node->parentId,
			createdBy: $node->createdBy,
			createdAt: $node->createdAt,
			updatedAt: $node->updatedAt,
			xmlId: $node->xmlId,
			sort: $node->sort,
			isActive: $node->active,
			isGlobalActive: $node->globalActive,
			depth: $node->depth,
			accessCode: $node->accessCode,
			isIblockSource: false,
		);
	}
}