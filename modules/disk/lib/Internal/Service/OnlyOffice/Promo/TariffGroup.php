<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

enum TariffGroup: string
{
	case Starter = 'starter';
	case Extendable = 'extendable';
	case LargeEnterprise = 'large_enterprise';
}
