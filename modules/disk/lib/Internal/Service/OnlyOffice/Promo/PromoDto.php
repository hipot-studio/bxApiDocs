<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

class PromoDto
{
	public function __construct(
		public readonly PromoType $type,
		public readonly ?string $code = null,
		public readonly array $params = [],
	)
	{
	}
}
