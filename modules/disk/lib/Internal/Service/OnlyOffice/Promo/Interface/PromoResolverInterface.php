<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\PromoDto;

interface PromoResolverInterface
{
	/**
	 * @return PromoDto|null
	 */
	public function resolve(): ?PromoDto;
}
