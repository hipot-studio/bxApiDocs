<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\Tariff;

enum SubscriptionState: string
{
	case None = 'none';
	case DemoAvailable = 'demoAvailable';
	case DemoActive = 'demoActive';
	case Purchased = 'purchased';
	case Expiring = 'expiring';
	case Expired = 'expired';
}
