<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\IncreaseLimitRequestMessageDto;

interface IncreaseLimitRequestMessageResolverInterface
{
	public function resolve(): ?IncreaseLimitRequestMessageDto;
}
