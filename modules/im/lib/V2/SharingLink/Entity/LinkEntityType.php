<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Entity;

use JsonSerializable;

enum LinkEntityType: string implements JsonSerializable
{
	case Chat = 'CHAT';
	case GuestChat = 'GUEST_CHAT';

	/**
	 * Entity types whose ENTITY_ID is a chat id — the single source of truth for chat-scoped
	 * queries/deletes once non-chat entity types appear.
	 *
	 * @return list<string>
	 */
	public static function chatTypeValues(): array
	{
		return [self::Chat->value, self::GuestChat->value];
	}

	public function jsonSerialize(): string
	{
		return $this->value;
	}
}
