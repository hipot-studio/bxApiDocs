<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker;

use Bitrix\Main\Type\DateTime;

class RecentItem
{
	public function __construct(
		public readonly int $id,
		public readonly int $packId,
		public readonly string $packType,
		public readonly DateTime $dateCreate,
	)
	{}
}
