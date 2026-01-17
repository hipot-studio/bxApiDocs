<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;

class ThemeProvider
{
	private const CACHE_TTL = 604800; // week
	private const CACHE_DIR = 'mobile_theme_colors';

	private int $userId;
	private string $templateId;

	public function __construct(int $userId, string $templateId = SITE_TEMPLATE_ID)
	{
		$this->userId = $userId;
		$this->templateId = $templateId;
	}

	/**
	 * @return array|null
	 */
	public function getCurrentTheme(): ?array
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		$themePicker = new \Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker(
			$this->templateId,
			false,
			$this->userId
		);

		$currentTheme = $themePicker->getCurrentTheme();

		if (!is_array($currentTheme) || !isset($currentTheme['id']))
		{
			return null;
		}

		if ($themePicker->isCustomThemeId($currentTheme['id']))
		{
			$initialDefaultThemeId = $themePicker->getInitialDefaultThemeId();
			$currentTheme = $themePicker->getTheme($initialDefaultThemeId);

			if (!is_array($currentTheme) || !isset($currentTheme['id']))
			{
				return null;
			}
		}

		return $currentTheme;
	}
}
