<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

enum TariffState: string
{
	case Free = 'free';
	case Demo = 'demo';
	case Downgraded = 'downgraded';
	case Active = 'active';
	case AlmostExpired = 'almostExpired';
	case Expired = 'expired';
	case Autopay = 'autopay';
}
