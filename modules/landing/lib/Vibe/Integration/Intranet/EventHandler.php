<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet;

use Bitrix\Bitrix24\Feature;
use Bitrix\Landing\Vibe\Facade\Portal;
use Bitrix\Landing\Vibe\Vibe;
use Bitrix\Main;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
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

	public static function onLicenseHasChanged(Event $event): void
	{
		// todo: no need add always
		$checker = new Portal();
		if (
			$event->getParameter('licenseType')
			&& !$checker->checkFeature(Portal::VIBE_FEATURE, $event->getParameter('licenseType'))
		)
		{
			EventManager::getInstance()->unregisterEventHandler(
				'intranet',
				'onLicenseHasChanged',
				'landing',
				self::class,
				'onLicenseHasChanged'
			);

			// todo: del dbg, check free tariff mode
			// $vibe = Vibe::createMainVibe();
			// $vibe->setFreeTariffMode(true);
		}
	}
}