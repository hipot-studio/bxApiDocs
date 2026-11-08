<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\SupportFeature;
use Bitrix\Mobile\Provider\SupportProvider;

/**
 * Data of the support card: the two destinations it can open. The bot is taken when the portal has
 * one and the helpdesk is what the card always falls back to, so both travel together.
 *
 * Nothing here is needed to draw the card - its icon and phrase are static - so the data is asked
 * for the tap alone.
 */
final class SupportCardProvider
{
	/**
	 * Id of the support bot, or 0 when the portal has none. The feature flag stays in front of the
	 * imbot session walk behind the bot lookup.
	 */
	public function getBotId(): int
	{
		if (!Feature::isEnabled(SupportFeature::class))
		{
			return 0;
		}

		return (int)(new SupportProvider())->getBotId();
	}

	/**
	 * Helpdesk address of the current language: a per-language constant of the ui module, so the
	 * loaded module is the whole condition of having an address at all.
	 */
	public function getHelpdeskUrl(): ?string
	{
		return Loader::includeModule('ui') ? \Bitrix\UI\Util::getHelpdeskUrl(true) : null;
	}
}
