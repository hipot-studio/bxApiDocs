<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\Internals\Task\TagCollection;
use Bitrix\Tasks\Internals\Task\TagObject;
use Bitrix\Tasks\V2\Internal\Entity;

class TagMapper
{
	public function mapToCollection(?TagCollection $tags): Entity\TagCollection
	{
		if ($tags === null)
		{
			return new Entity\TagCollection();
		}

		$entities = [];
		foreach ($tags as $tag)
		{
			$entities[] = $this->mapToEntity($tag);
		}

		return new Entity\TagCollection(...$entities);
	}

	public function mapToEntity(TagObject $tag): Entity\Tag
	{
		return new Entity\Tag(
			id: $tag->getId(),
			name: $tag->getName(),
			owner: new Entity\User(id: $tag->getUserId()),
			group: new Entity\Group(id: $tag->getGroupId())
		);
	}
}
