<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Tag;

class TagDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public string $name;
	public ?int $ownerId;
	#[RelationToOne('ownerId', 'id')]
	public ?UserDto $owner;
	public ?int $groupId;
	#[RelationToOne('groupId', 'id')]
	public ?GroupDto $group;
	public ?int $taskId;
	#[RelationToOne('taskId', 'id')]
	public ?TaskDto $task;

	public static function fromEntity(?Tag $tag, ?Request $request = null): ?self
	{
		if (!$tag)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $tag->id;
		}
		if (empty($select) || in_array('name', $select, true))
		{
			$dto->name = $tag->name;
		}
		if ($request?->getRelation('owner') !== null)
		{
			$dto->owner = $tag->owner ? UserDto::fromEntity($tag->owner, $request->getRelation('owner')->getRequest()) : null;
		}
		if ($request?->getRelation('group') !== null)
		{
			$dto->group = $tag->group ? GroupDto::fromEntity($tag->group, $request->getRelation('group')->getRequest()) : null;
		}
		if ($request?->getRelation('task') !== null)
		{
			$dto->task = $tag->task ? TaskDto::fromEntity($tag->task, $request->getRelation('task')->getRequest()) : null;
		}

		return $dto;
	}
}
