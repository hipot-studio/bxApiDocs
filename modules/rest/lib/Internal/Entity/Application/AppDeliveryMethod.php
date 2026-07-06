<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppDeliveryMethod
{
	case Application;
	case ConfigurationImport;
}
