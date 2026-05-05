<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo\Interface;

use Bitrix\Disk\Internal\Service\OnlyOffice\Promo\TariffGroup;

interface TariffGroupResolverInterface
{
	/**
	 * @return TariffGroup|null
	 */
	public function resolve(): ?TariffGroup;
}
