<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Group;

class GroupDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public string $name;
	public ?FileDto $image;
	/** @see \Bitrix\Socialnetwork\Item\Workgroup\Type */
	public ?string $type;
	public ?bool $isVisible;

	public static function fromEntity(?Group $group, ?Request $request = null): ?self
	{
		if (!$group)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $group->id;
		}
		if (empty($select) || in_array('name', $select, true))
		{
			$dto->name = $group->name;
		}
		if (empty($select) || in_array('image', $select, true))
		{
			$dto->image = $group->image ? FileDto::fromEntity($group->image, $request) : null;
		}
		if (empty($select) || in_array('type', $select, true))
		{
			$dto->type = $group->type;
		}
		if (empty($select) || in_array('isVisible', $select, true))
		{
			$dto->isVisible = $group->isVisible;
		}

		return $dto;
	}
}
