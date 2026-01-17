<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity;

class TagMapper
{
	public function mapToCollection(array $tags): Entity\TagCollection
	{
		$entities = [];
		foreach ($tags as $tag)
		{
			$entities[] = $this->mapToEntity($tag);
		}

		return new Entity\TagCollection(...$entities);
	}

	public function mapToEntity(array $tag): Entity\Tag
	{
		$entityFields = [
			'id' => (int)($tag['ID'] ?? null),
			'name' => (string)($tag['NAME'] ?? null),
			'task' => new Entity\Task(id: (int)($tag['TASK_ID'] ?? 0)),
		];

		$ownerId = (int)($tag['USER_ID'] ?? 0);
		$groupId = (int)($tag['GROUP_ID'] ?? 0);
		if ($ownerId > 0)
		{
			$entityFields['owner'] = new Entity\User(id: $ownerId);
		}

		if ($groupId > 0)
		{
			$entityFields['group'] = new Entity\Group(id: $groupId);
		}

		return Entity\Tag::mapFromArray($entityFields);
	}
}
