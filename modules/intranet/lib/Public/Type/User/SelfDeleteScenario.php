<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Type\User;

enum SelfDeleteScenario: string
{
	case NotLastAdmin = 'NotLastAdmin';
	case LastAdminPayedLicense = 'LastAdminPayedLicense';
	case LastAdminFreeLicense = 'LastAdminFreeLicense';
	case LastUserAdmin = 'LastAdmin';
}
