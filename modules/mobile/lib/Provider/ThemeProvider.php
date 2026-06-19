<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Main\Loader;
use Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker;

final class ThemeProvider
{
	private int $userId;
	private string $templateId;
	private ThemePicker $themePicker;

	public function __construct(int $userId, string $templateId = SITE_TEMPLATE_ID)
	{
		$this->userId = $userId;
		$this->templateId = $templateId;

		$this->themePicker = new ThemePicker(
			$this->templateId,
			false,
			$this->userId
		);
	}

	public function getCurrentTheme(): ?array
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		$currentTheme = $this->themePicker->getCurrentTheme();

		if (!is_array($currentTheme) || !isset($currentTheme['id']))
		{
			return null;
		}

		if ($this->themePicker->isCustomThemeId($currentTheme['id']))
		{
			return $this->getFallbackTheme();
		}

		$currentTheme['ownerId'] = $this->userId;

		return $currentTheme;
	}

	public function getFallbackTheme(): ?array
	{
		$initialDefaultThemeId = $this->themePicker->getInitialDefaultThemeId();
		$theme = $this->themePicker->getTheme($initialDefaultThemeId);

		if (is_array($theme) && isset($theme['id']))
		{
			$theme['ownerId'] = $this->userId;

			return $theme;
		}

		return null;
	}

	public function isSvgTheme(?array $theme): bool
	{
		if ($theme === null)
		{
			return false;
		}

		$previewImage = (string)($theme['previewImage'] ?? '');
		$previewImage = mb_strtolower(trim($previewImage));

		return str_ends_with($previewImage, '.svg');
	}
}
