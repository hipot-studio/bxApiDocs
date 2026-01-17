<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task;

use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Chat;

class ChatDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;
	public ?int $entityId;
	public ?string $entityType;

	public static function fromEntity(?Chat $chat, ?Request $request = null): ?self
	{
		if (!$chat)
		{
			return null;
		}
		$select = $request?->select?->getList(true) ?? [];
		$dto = new self();
		if (empty($select) || in_array('id', $select, true))
		{
			$dto->id = $chat->id;
		}
		if (empty($select) || in_array('entityId', $select, true))
		{
			$dto->entityId = $chat->entityId;
		}
		if (empty($select) || in_array('entityType', $select, true))
		{
			$dto->entityType = $chat->entityType;
		}

		return $dto;
	}
}
