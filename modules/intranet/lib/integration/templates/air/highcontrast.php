<?php

namespace Bitrix\Intranet\Integration\Templates\Air;

use Bitrix\Intranet\Composite\CacheProvider;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Single source of truth about the high contrast mode for the portal template.
 *
 * The option lives in CUserOptions and is read and written only here: neither the
 * template nor its javascript may touch it directly.
 */
final class HighContrast
{
	public const OPTION_CATEGORY = 'ui';
	public const OPTION_NAME = 'high_contrast';
	public const HTML_CLASS = '--ui-context-high-contrast';
	public const EXTENSION = 'ui.design-tokens.air.high-contrast';
	public const BUNDLE = '/src/high-contrast/neutralizer.css';

	private const VALUE_ENABLED = 'Y';
	private const VALUE_DISABLED = 'N';

	/**
	 * Tells whether the user browses the portal in the high contrast mode.
	 *
	 * @param int|null $userId Current user when null.
	 * @return bool
	 */
	public static function isEnabled(?int $userId = null): bool
	{
		$userId = self::resolveUserId($userId);
		if ($userId <= 0)
		{
			return false;
		}

		$value = \CUserOptions::GetOption(
			self::OPTION_CATEGORY,
			self::OPTION_NAME,
			self::VALUE_DISABLED,
			$userId,
		);

		return $value === self::VALUE_ENABLED;
	}

	/**
	 * Turns the high contrast mode on or off for the user.
	 *
	 * @param bool $enabled
	 * @param int|null $userId Current user when null.
	 * @return void
	 */
	public static function setEnabled(bool $enabled, ?int $userId = null): void
	{
		$userId = self::resolveUserId($userId);
		if ($userId <= 0)
		{
			return;
		}

		$saved = \CUserOptions::SetOption(
			self::OPTION_CATEGORY,
			self::OPTION_NAME,
			$enabled ? self::VALUE_ENABLED : self::VALUE_DISABLED,
			false,
			$userId,
		);
		if ($saved === false)
		{
			throw new \RuntimeException('Failed to save high contrast mode');
		}

		// composite cache stores the rendered hook on <html>, so it is dropped even when the value did not change
		$currentUser = $GLOBALS['USER'] ?? null;
		$sendHeader = $currentUser instanceof \CUser
			&& $currentUser->IsAuthorized()
			&& (int)$currentUser->GetID() === $userId;
		CacheProvider::deleteUserCache($userId, $sendHeader);
	}

	/**
	 * Returns the class the template puts on <html> to switch the mode on.
	 *
	 * @return string
	 */
	public static function getHtmlClass(): string
	{
		return self::HTML_CLASS;
	}

	/**
	 * Returns the name of the extension holding the high contrast palette.
	 *
	 * @return string
	 */
	public static function getExtensionName(): string
	{
		return self::EXTENSION;
	}

	/**
	 * Returns the template relative path of the neutralizer bundle.
	 *
	 * @return string
	 */
	public static function getBundlePath(): string
	{
		return self::BUNDLE;
	}

	private static function resolveUserId(?int $userId): int
	{
		if ($userId !== null)
		{
			return $userId;
		}

		return (int)CurrentUser::get()->getId();
	}
}
