<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

class IncreaseLimitRequestMessageDto
{
	public function __construct(
		public readonly int $chatId,
		public readonly string $dialogId,
		public readonly string $buyLink,
	)
	{
	}
}
