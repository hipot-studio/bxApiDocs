<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Main;

use Bitrix\Main;

class PortalLicenseProvider
{
	public function getLicenseInfo(): array
	{
		$license = Main\Application::getInstance()->getLicense();

		$licenseExpiresAt = $license->getExpireDate()?->getTimestamp() ?? 0;
		$isLicenseExpired = $licenseExpiresAt > 0 && $licenseExpiresAt < time();
		$isPaidLicense = !$license->isDemo() && (!$license->isTimeBound() || !$isLicenseExpired);

		return [
			'licenseName' => $license->getName(),
			'region' => $license->getRegion(),
			'isTimeBound' => $license->isTimeBound(),
			'isPaid' => $isPaidLicense,
			'isDemo' => $license->isDemo(),
			'isFree' => false,
		];
	}
}
