<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Bitrix24\License;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Rest\Marketplace\Url;

class MarketUrlProvider
{

	private bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');

	}

	public function getBuyUrl(): Uri
	{
		if (
			$this->isModuleIncluded
			&& \CBitrix24::getLicenseFamily() !== 'nfr'
			&& (
				!\CBitrix24::isLicensePaid()
				|| \CBitrix24::isArchivalLicense()
				|| !Manager::getInstance()->isEditionCompatible(\CBitrix24::getLicenseType())
			)
		)
		{
			return new Uri(Url::getSubscriptionBuyUrl());
		}

		return new Uri(License\Market::getDefaultBuyPath());
	}
}