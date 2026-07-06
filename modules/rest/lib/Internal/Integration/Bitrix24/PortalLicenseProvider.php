<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Bitrix24;

use Bitrix\Main;

class PortalLicenseProvider
{
	private bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Main\Loader::includeModule('bitrix24');
	}

	public function getLicenseInfo(): ?array
	{
		if (!$this->isModuleIncluded) {
			return null;
		}

		return [
			'licenseType' => \CBitrix24::getLicenseType(),
			'licenseFamily' => \CBitrix24::getLicenseFamily(),
			'licenseName' => \CBitrix24::getLicenseName(),
			'region' => \CBitrix24::getPortalZone() ?: null,
			'isPaid' => \CBitrix24::isLicensePaid(),
			'isDemo' => \CBitrix24::IsDemoLicense(),
			'isFree' => \CBitrix24::isFreeLicense(),
			'isLicenseNeverPaid' => \CBitrix24::isLicenseNeverPayed(),
		];
	}
}
