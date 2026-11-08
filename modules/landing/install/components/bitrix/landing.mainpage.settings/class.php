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

		$renderOptions = $this->arParams['renderOptions'] ?? new Vibe\VibeRenderOptions();
		if (!$renderOptions instanceof Vibe\VibeRenderOptions)
		{
			$renderOptions = new Vibe\VibeRenderOptions();
		}

		$this->arResult['CONTAINER_ID'] = 'vibe-settings-' . Random::getString(8);
		$this->arResult['VIBES_DATA_JSON'] = Json::encode([
			'vibes' => $this->applyRenderOptions(
				(new Settings\Manager($vibes))->getData(),
				$renderOptions,
			),
		]);

		$this->IncludeComponentTemplate();
	}

	/**
	 * Applies VibeSection rendering options to each Vibe data item.
	 *
	 * @param array<int, array<string, mixed>> $vibesData
	 * @param Vibe\VibeRenderOptions $renderOptions
	 * @return array<int, array<string, mixed>>
	 */
	private function applyRenderOptions(array $vibesData, Vibe\VibeRenderOptions $renderOptions): array
	{
		return array_map(
			static fn(array $vibeData): array => [
				...$vibeData,
				...$renderOptions->toArray(),
			],
			$vibesData,
		);
	}
}
