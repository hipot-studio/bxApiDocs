<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface\IncreaseLimitRequestMessageResolverInterface;

class NullIncreaseLimitRequestMessageResolver implements IncreaseLimitRequestMessageResolverInterface
{
	public function resolve(): ?IncreaseLimitRequestMessageDto
	{
		return null;
	}
}
