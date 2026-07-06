<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppType: string
{
	case Standard = 'N';
	case OnlyApi = 'A';
	case Configuration = 'C';
	case SmartRobots = 'R';
	case BicDashboard = 'B';
}
