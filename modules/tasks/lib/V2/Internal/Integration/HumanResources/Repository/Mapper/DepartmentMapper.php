<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository\Mapper;

use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Item\Node;
use Bitrix\Tasks\V2\Internal\Entity\File;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\Department;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\DepartmentCollection;

class DepartmentMapper
{
	public function mapToCollection(NodeCollection $nodes, ?string $avatarPath = null): DepartmentCollection
	{
		$collection = new DepartmentCollection();

		foreach ($nodes as $node)
		{
			$collection->add($this->mapToEntity($node, $avatarPath));
		}

		return $collection;
	}

	public function mapToEntity(Node $node, ?string $avatarPath = null): Department
	{
		return new Department(
			id: $node->id,
			name: $node->name,
			image: new File(src: $avatarPath),
			accessCode: $node->accessCode,
		);
	}
}
