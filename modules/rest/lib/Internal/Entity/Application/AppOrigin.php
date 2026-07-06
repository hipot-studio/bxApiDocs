<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppOrigin
{
	case Local;
	case Marketplace;
}
