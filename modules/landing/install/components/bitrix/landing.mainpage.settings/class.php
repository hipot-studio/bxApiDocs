<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Landing\Vibe;
use Bitrix\Landing\Vibe\Integration\Intranet\Settings;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;

\CBitrixComponent::includeComponentClass('bitrix:landing.base');

class LandingMainpageSettingsComponent extends \CBitrixComponent
{
	public function executeComponent(): void
	{
		$vibes = [];
		foreach ($this->arParams['vibes'] ?? [] as $item)
		{
			if (
				$item instanceof Vibe\Vibe
				&& $item->isAvailable()
			)
			{
				$vibes[] = $item;
			}
		}

		$this->arResult['CONTAINER_ID'] = 'vibe-settings-' . Random::getString(8);
		$this->arResult['VIBES_DATA_JSON'] = Json::encode([
			'vibes' => (new Settings\Manager($vibes))->getData(),
		]);

		$this->IncludeComponentTemplate();
	}
}
