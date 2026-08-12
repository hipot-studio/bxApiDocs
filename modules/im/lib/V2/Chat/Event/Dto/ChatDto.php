<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Event\Dto;

final class ChatDto
{
	public function __construct(
		public readonly int $id,
		public readonly ?int $parentChatId,
		public readonly string $type,
		public readonly string $extendedType,
		public readonly ?string $entityType,
		public readonly ?string $entityId,
	) {}

	public static function fromChat(\Bitrix\Im\V2\Chat $chat): self
	{
		return new self(
			id: (int)$chat->getId(),
			parentChatId: $chat->getParentChatId(),
			type: $chat->getType(),
			extendedType: $chat->getExtendedType(),
			entityType: $chat->getEntityType(),
			entityId: $chat->getEntityId(),
		);
	}
}
