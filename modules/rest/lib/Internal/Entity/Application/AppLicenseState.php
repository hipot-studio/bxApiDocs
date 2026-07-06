<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppLicenseState
{
	case Active;
	case Trial;
	case Demo;
}
