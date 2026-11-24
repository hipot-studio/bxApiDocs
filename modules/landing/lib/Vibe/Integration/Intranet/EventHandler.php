<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet;

use Bitrix\Landing\Vibe\Facade\Portal;
use Bitrix\Main;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;

class EventHandler
{
	public static function onSettingsProvidersCollect(Main\Event $event): void
	{
		$prevSort = 100;
		$providers = $event->getParameter('providers');
		$keys = array_values($providers);
		if (isset($keys[1]))
		{
			$secondProvider = $keys[1];
			$prevSort = $secondProvider->getSort();
		}

		$provider = (new Settings\VibeSettingsPageProvider());
		$provider->setSort($prevSort + 1);

		$event->addResult(new Main\EventResult(Main\EventResult::SUCCESS, [
			'providers' => [
				$provider->getType() => $provider,
			],
		]));
	}

	/**
	 * Registered by Vibe::createPageByTemplateAtFreeTariff(). A license without the vibe feature
	 * ends the free tariff mode of the portal; a license with the feature keeps it untouched.
	 * @param Event $event
	 * @return void
	 */
	public static function onLicenseHasChanged(Event $event): void
	{
		$licenseType = (string)$event->getParameter('licenseType');
		if ($licenseType === '')
		{
			return;
		}

		$portal = new Portal();
		if ($portal->checkFeature(Portal::VIBE_FEATURE, $licenseType))
		{
			return;
		}

		$portal->setFreeTariffMode(false);
		EventManager::getInstance()->unregisterEventHandler(
			'intranet',
			'onLicenseHasChanged',
			'landing',
			self::class,
			'onLicenseHasChanged'
		);
	}
}