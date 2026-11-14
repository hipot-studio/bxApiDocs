<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Bitrix24;
use Bitrix\Crm\Feature\AvitoTrackingSource;
use Bitrix\Crm\Integration\Bitrix24\Product;
use Bitrix\Crm\Integration\Sender\Utm;
use Bitrix\Main\Config;
use Bitrix\Main\Loader;

/**
 * The switches of the portal the catalog of the end-to-end analytics depends on, read the way legacy reads them.
 *
 * Not final: tests substitute the switches.
 *
 * @internal
 */
class TrackingEnvironment
{
	private const SOCIAL_REF_DOMAIN_OPTION = '~crm_tracking_soc_ref_domain';
	private const OWN_SOURCES_TARIFF = 'crm_tracking_sources_own';

	/** Russia, Kazakhstan, Belarus or Uzbekistan. */
	public function isRussianRegion(): bool
	{
		return Product::isRegionRussian();
	}

	public function isRussianRegionStrict(): bool
	{
		return Product::isRegionRussian(true);
	}

	public function isAvitoEnabled(): bool
	{
		return Config\Feature::isEnabled(AvitoTrackingSource::class);
	}

	public function isSocialRefDomainUsed(): bool
	{
		return Config\Option::get('crm', self::SOCIAL_REF_DOMAIN_OPTION, 'Y') === 'Y';
	}

	public function canUseSender(): bool
	{
		return Utm::canUse();
	}

	/**
	 * @return list<string>
	 */
	public function getSenderUtmSources(): array
	{
		return array_values(array_map('strval', Utm::getUtmSources()));
	}

	public function hasOwnSourcesTariff(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Bitrix24\Feature::isFeatureEnabled(self::OWN_SOURCES_TARIFF);
	}

	/** The integration with VKontakte is allowed in the zone of the portal. */
	public function isVkAvailable(): bool
	{
		return Product::isVkAvailable();
	}
}
