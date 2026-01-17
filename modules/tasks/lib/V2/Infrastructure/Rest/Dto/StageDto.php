<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Stage;

class StageDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $title;
	public ?string $color;

	public static function fromEntity(?Stage $stage, ?Request $request = null): ?self
	{
		if (!$stage)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $stage->id;
		}
		if (empty($select) || in_array('title', $select, true))
		{
			$dto->title = $stage->title;
		}
		if (empty($select) || in_array('color', $select, true))
		{
			$dto->color = $stage->color;
		}

		return $dto;
	}
}
