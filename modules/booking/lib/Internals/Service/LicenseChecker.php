<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use CBitrix24;

class LicenseChecker
{
	public function isPaidB24OrBox(): bool
	{
		if ($this->isBox())
		{
			return true;
		}

		return CBitrix24::IsLicensePaid() || CBitrix24::IsNfrLicense();
	}

	public function isBox(): bool
	{
		return !Loader::includeModule('bitrix24');
	}
}
