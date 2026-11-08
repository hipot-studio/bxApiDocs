<?php

namespace Bitrix\Landing\Vibe;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\ObjectPropertyException;

/**
 * Render Vibe views and maybe pages
 */
class Renderer
{
	private Vibe $vibe;

	public function __construct(Vibe $vibe)
	{
		if ($vibe->getSiteId() === null)
		{
			throw new ObjectPropertyException('Vibe have not created site');
		}

		$this->vibe = $vibe;
	}

	public function renderView(): void
	{
		if (!$this->vibe->canView())
		{
			return;
		}

		global $APPLICATION;
		$APPLICATION->IncludeComponent(
			'bitrix:landing.mainpage.pub',
			'',
			[
				'MODULE_ID' => $this->vibe->getModuleId(),
				'EMBED_ID' => $this->vibe->getEmbedId(),
			],
		);
	}

	/**
	 * Renders the Vibe settings section.
	 *
	 * @param VibeRenderOptions|null $renderOptions
	 * @return void
	 */
	public function renderSettings(?VibeRenderOptions $renderOptions = null): void
	{
		self::includeSettingsComponent([$this->vibe], $renderOptions);
	}

	/**
	 * @param array<Vibe> $vibes
	 * @param VibeRenderOptions|null $renderOptions
	 * @return void
	 * @throws ArgumentTypeException
	 */
	public static function renderSettingsGroup(?array $vibes = null, ?VibeRenderOptions $renderOptions = null): void
	{
		foreach ($vibes as $index => $item)
		{
			if (!$item instanceof Vibe)
			{
				throw new ArgumentTypeException('vibes[' . $index . ']', Vibe::class);
			}
		}

		self::includeSettingsComponent($vibes, $renderOptions);
	}

	/**
	 * @param Vibe[] $vibes
	 * @param VibeRenderOptions|null $renderOptions
	 */
	private static function includeSettingsComponent(array $vibes, ?VibeRenderOptions $renderOptions = null): void
	{
		global $APPLICATION;
		$APPLICATION->IncludeComponent(
			'bitrix:landing.mainpage.settings',
			'',
			[
				'vibes' => $vibes,
				'renderOptions' => $renderOptions ?? new VibeRenderOptions(),
			],
		);
	}
}
