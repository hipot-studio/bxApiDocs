<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Main\Loader;

class LicenseChecker
{
	public function isPaidOrBox(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return (
			\CBitrix24::IsLicensePaid()
			|| \CBitrix24::IsNfrLicense()
		);
	}
}
