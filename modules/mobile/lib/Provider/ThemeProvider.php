<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker;

final class ThemeProvider
{
	private const FALLBACK_TEMPLATE_ID = 'bitrix24';
	private const UNSUPPORTED_BACKGROUND_EXTENSIONS = ['svg', 'webp'];

	private int $userId;
	private string $templateId;
	private ?ThemePicker $themePicker = null;

	public function __construct(int $userId, ?string $templateId = null)
	{
		$this->userId = $userId;
		$this->templateId = $templateId ?? self::resolveSiteTemplateId();
	}

	/**
	 * Not necessarily the theme the user picked: a theme the application is unable to draw is
	 * answered with the fallback one. The answer is the same for every caller on purpose - one
	 * reading only the text color of the theme has to name a color fit for the background that is
	 * actually drawn, and the background is drawn from whatever this method returned.
	 */
	public function getCurrentTheme(): ?array
	{
		$themePicker = $this->getThemePicker();
		if ($themePicker === null)
		{
			return null;
		}

		$currentTheme = $themePicker->getCurrentTheme();

		if (!is_array($currentTheme) || !isset($currentTheme['id']))
		{
			return null;
		}

		if (
			$themePicker->isCustomThemeId($currentTheme['id'])
			|| $this->hasUnsupportedBackgroundImage($currentTheme)
		)
		{
			return $this->getFallbackTheme();
		}

		$currentTheme['ownerId'] = $this->userId;

		return $currentTheme;
	}

	public function getFallbackTheme(): ?array
	{
		$themePicker = $this->getThemePicker();
		if ($themePicker === null)
		{
			return null;
		}

		$initialDefaultThemeId = $themePicker->getInitialDefaultThemeId();
		$theme = $themePicker->getTheme($initialDefaultThemeId);

		if (is_array($theme) && isset($theme['id']))
		{
			$theme['ownerId'] = $this->userId;

			return $theme;
		}

		return null;
	}

	/**
	 * The application draws the background of a theme itself, and the formats it decodes are not all
	 * of those the theme catalog holds.
	 */
	private function hasUnsupportedBackgroundImage(?array $theme): bool
	{
		if ($theme === null)
		{
			return false;
		}

		$extension = self::extractExtension(self::resolveBackgroundImage($theme));

		return in_array($extension, self::UNSUPPORTED_BACKGROUND_EXTENSIONS, true);
	}

	/**
	 * The application reads the background out of prefetchImages, which holds either a single image
	 * or a list of them, and takes the first non-empty one. previewImage names the theme in the web
	 * picker and is never drawn as a background, so it answers only for themes without prefetch ones.
	 */
	private static function resolveBackgroundImage(array $theme): string
	{
		$prefetchImages = $theme['prefetchImages'] ?? null;
		$candidates = is_array($prefetchImages) ? $prefetchImages : [$prefetchImages];

		foreach ($candidates as $candidate)
		{
			if (is_string($candidate) && trim($candidate) !== '')
			{
				return $candidate;
			}
		}

		return (string)($theme['previewImage'] ?? '');
	}

	private static function extractExtension(string $image): string
	{
		$image = trim($image);
		$path = (new Uri($image))->getPath();

		return mb_strtolower((string)pathinfo($path === '' ? $image : $path, PATHINFO_EXTENSION));
	}

	/**
	 * The picker reads the theme of the entity and includes the theme list of the template, so it is
	 * built no earlier than a theme is asked for, and never without the module that owns it.
	 */
	private function getThemePicker(): ?ThemePicker
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		return $this->themePicker ??= $this->resolveThemePicker();
	}

	/**
	 * The intranet keeps a picker of its own for the current user and the template of the site: a
	 * caller asking for exactly that gets it instead of paying for a second one. It is asked for
	 * only in that context, because getInstance() builds the shared picker when there is none yet.
	 *
	 * What comes back is whatever instance was built first: getInstance() ignores the entity type it
	 * is given, so a picker of a workgroup would answer here with the theme of that workgroup. The
	 * shared one is taken only when it describes the same entity, otherwise a picker of our own is.
	 */
	private function resolveThemePicker(): ThemePicker
	{
		$sharedPicker = $this->wantsSharedThemePicker() ? ThemePicker::getInstance() : null;

		return $this->describesRequestedUser($sharedPicker)
			? $sharedPicker
			: new ThemePicker($this->templateId, false, $this->userId)
		;
	}

	private function wantsSharedThemePicker(): bool
	{
		return $this->templateId === self::resolveSiteTemplateId()
			&& $this->userId === (int)CurrentUser::get()->getId()
		;
	}

	private function describesRequestedUser(?ThemePicker $picker): bool
	{
		return $picker !== null
			&& $picker->getEntityType() === ThemePicker::ENTITY_TYPE_USER
			&& (int)$picker->getUserId() === $this->userId
		;
	}

	/**
	 * The fallback repeats the one of ThemePicker::getInstance(), so both name the same template
	 * where the constant is missing: json actions, agents, cli.
	 */
	private static function resolveSiteTemplateId(): string
	{
		return defined('SITE_TEMPLATE_ID') ? (string)SITE_TEMPLATE_ID : self::FALLBACK_TEMPLATE_ID;
	}
}
