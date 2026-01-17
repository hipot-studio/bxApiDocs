<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Entity\CrmItem;

class CrmItemDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?string $type;
	public ?string $title;

	public static function fromEntity(?CrmItem $crmItem, ?Request $request = null): ?self
	{
		if (!$crmItem)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $crmItem->id;
		}
		if (empty($select) || in_array('type', $select, true))
		{
			$dto->type = $crmItem->type;
		}
		if (empty($select) || in_array('title', $select, true))
		{
			$dto->title = $crmItem->title;
		}

		return $dto;
	}
}
