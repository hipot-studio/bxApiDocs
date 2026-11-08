<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24;
use Bitrix\Intranet;
use Bitrix\Main\Loader;

/**
 * Integration layer for the portal settings of the bitrix24/intranet domain.
 * All other classes in the mobile module must use this class instead of calling
 * Bitrix24\Service\PortalSettings, Intranet\Service\PortalSettings or the portal
 * options behind them directly.
 */
final class PortalSettings
{
	/**
	 * Title of the portal, the same string the web shows. The owning service already carries the
	 * whole fallback chain (site_title -> portal name -> server name on a box, the product name on
	 * the cloud), so this side reads its answer instead of repeating the chain over options.
	 *
	 * An empty string is the neutral answer: neither module installed, no service registered or any
	 * failure inside it must cost the caller the title only.
	 */
	public function getTitle(): string
	{
		try
		{
			$settings = $this->getService();

			return $settings === null ? '' : trim($settings->titleSettings()->getTitle());
		}
		catch (\Throwable)
		{
			return '';
		}
	}

	/**
	 * Cloud service first: on the cloud it is the one that knows the product name, and it falls back
	 * to the intranet settings itself when a particular setting is registered there only.
	 */
	private function getService(): ?Intranet\Service\PortalSettings
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Bitrix24\Service\PortalSettings::getInstance();
		}

		if (Loader::includeModule('intranet'))
		{
			return Intranet\Service\PortalSettings::getInstance();
		}

		return null;
	}
}
