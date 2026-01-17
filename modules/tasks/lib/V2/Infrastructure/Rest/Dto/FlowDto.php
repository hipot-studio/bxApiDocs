<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Entity\Flow;

class FlowDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $name;

	public static function fromEntity(?Flow $flow, ?Request $request = null): ?self
	{
		if (!$flow)
		{
			return null;
		}
		$dto = new self();
		$select = $request?->select?->getList(true);
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $flow->id;
		}

		if (empty($select) || in_array('name', $select, true))
		{
			$dto->name = $flow->name;
		}

		return $dto;
	}
}
