<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

enum SelfHostedLicenseState: string
{
	case None = 'NONE';
	case Active = 'ACTIVE';
	/**
	 * The term is over, but the grace after it is not: the reports keep working and the renewal is still bought
	 * at the price of a renewal.
	 */
	case Grace = 'GRACE';
	case Expired = 'EXPIRED';
}
