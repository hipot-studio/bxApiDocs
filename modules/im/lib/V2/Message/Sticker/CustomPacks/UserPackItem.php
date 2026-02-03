<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

class UserPackItem
{
	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly int $packId,
	){}
}
