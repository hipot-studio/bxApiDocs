<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Promo;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use CBitrix24;

class TariffGroupResolver
{
	private Configuration $diskConfig;

	public function __construct()
	{
		$this->diskConfig = Configuration::getInstance('disk');
	}

	/**
	 * It's only for cloud editions.
	 *
	 * @return TariffGroup|null
	 * @throws LoaderException
	 */
	public function resolve(): ?TariffGroup
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$licenseType = CBitrix24::getLicenseType();

		if ($licenseType === 'basic' || CBitrix24::isFreeLicense())
		{
			return TariffGroup::Starter;
		}

		$promo = $this->diskConfig->get('promo');

		if (!is_array($promo))
		{
			return null;
		}

		$cloudTariffGroups = $promo['cloud_tariff_groups'] ?? [];

		$extendableTariffs = $cloudTariffGroups['extendable'] ?? [];

		if (in_array($licenseType, $extendableTariffs, true))
		{
			return TariffGroup::Extendable;
		}

		$largeEnterpriseTariffs = $cloudTariffGroups['large_enterprise'] ?? [];
		if (in_array($licenseType, $largeEnterpriseTariffs, true))
		{
			return TariffGroup::LargeEnterprise;
		}

		return null;
	}
}
