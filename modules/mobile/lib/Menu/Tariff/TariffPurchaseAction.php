<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

/**
 * The action the tariff plate calls to. The values are the wire format of the plate data.
 */
enum TariffPurchaseAction: string
{
	case Buy = 'buy';
	case Upgrade = 'upgrade';
	case Renew = 'renew';
}
