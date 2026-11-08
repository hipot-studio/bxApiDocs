<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2026 Bitrix
 */
namespace Bitrix\Main\UserConsent;

/**
 * Class ConsentUrl
 * @package Bitrix\Main\UserConsent
 */
final class ConsentUrl
{
	/**
	 * Whitespace and control characters a browser ignores inside a URL. They are stripped before the
	 * scheme is looked at, so obfuscations such as `  javascript:` or `java\tscript:` cannot slip a
	 * disallowed scheme past the check.
	 */
	private const INVISIBLE_REGEX
		= '/[\x00-\x20\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u';

	/**
	 * An http(s) address with a non-empty host, or a path inside the portal. The `/(?![/\\])` branch
	 * rejects `//host` and `/\host`: formally relative references that navigate to a foreign host.
	 */
	private const ALLOWED_REGEX = '~^(?:https?://[^/?#\\\\]+|/(?![/\\\\]))~i';

	/**
	 * Byte ceiling for a value worth looking at: the storage column holds 4000 characters, and a
	 * character takes at most 4 bytes in UTF-8. A longer value cannot be stored whole, so it is
	 * rejected without scanning it — otherwise a single request could make the check walk megabytes.
	 */
	private const MAX_LENGTH = 4 * 4000;

	/**
	 * Returns true when the consent URL is safe to store and to render as a link.
	 *
	 * @param mixed $url URL as a string, null or an object with a string representation.
	 * @return bool
	 */
	public static function isAllowed(mixed $url): bool
	{
		if ($url === null)
		{
			return false;
		}

		$text = (string)$url;
		if ($text === '' || strlen($text) > self::MAX_LENGTH)
		{
			return false;
		}

		$stripped = self::stripInvisible($text);
		if ($stripped === null || $stripped === '')
		{
			return false;
		}

		$probe = self::stripInvisible(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
		if ($probe === null)
		{
			return false;
		}

		return self::matchesAllowed($stripped) && self::matchesAllowed($probe);
	}

	/**
	 * Returns the URL unchanged when it is allowed, null otherwise. Normalization is used for the
	 * decision only: an allowed value is returned exactly as it came in.
	 *
	 * @param mixed $url URL as a string, null or an object with a string representation.
	 * @return string|null
	 */
	public static function nullifyDisallowed(mixed $url): ?string
	{
		return self::isAllowed($url) ? (string)$url : null;
	}

	/**
	 * Returns the modifier list for the `save_data_modification` key of an ORM field.
	 *
	 * @return array
	 */
	public static function getSaveModificator(): array
	{
		return [[self::class, 'nullifyDisallowed']];
	}

	private static function matchesAllowed(string $url): bool
	{
		return preg_match(self::ALLOWED_REGEX, $url) === 1;
	}

	private static function stripInvisible(string $url): ?string
	{
		return preg_replace(self::INVISIBLE_REGEX, '', $url);
	}
}
