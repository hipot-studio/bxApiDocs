<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppPricingModel
{
	case Free;
	case Paid;
	case Subscription;
}
