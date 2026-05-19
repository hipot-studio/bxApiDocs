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

	public function renderSettings(): void
	{
		self::includeSettingsComponent([$this->vibe]);
	}

	/**
	 * @param array<Vibe> $vibes
	 * @return void
	 * @throws ArgumentTypeException
	 */
	public static function renderSettingsGroup(array $vibes = null): void
	{
		foreach ($vibes as $index => $item)
		{
			if (!$item instanceof Vibe)
			{
				throw new ArgumentTypeException('vibes[' . $index . ']', Vibe::class);
			}
		}

		self::includeSettingsComponent($vibes);
	}

	/**
	 * @param Vibe[] $vibes
	 */
	private static function includeSettingsComponent(array $vibes): void
	{
		global $APPLICATION;
		$APPLICATION->IncludeComponent(
			'bitrix:landing.mainpage.settings',
			'',
			[
				'vibes' => $vibes,
			],
		);
	}
}
