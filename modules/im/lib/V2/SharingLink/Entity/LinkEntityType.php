<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink\Entity;

use JsonSerializable;

enum LinkEntityType: string implements JsonSerializable
{
	case Chat = 'CHAT';

	public function jsonSerialize(): string
	{
		return $this->value;
	}
}
