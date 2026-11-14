<?php
namespace Bitrix\ImOpenLines\Tools;

use Bitrix\Main\Web\Uri;

/**
 * Class Url
 * @package Bitrix\ImOpenLines\Tools
 */
class Url
{
	private const ALLOWED_SCHEMES = ['http', 'https'];

	/** Whitespace, control characters and the backslash: a browser reads them differently than parse_url. */
	private const FORBIDDEN_CHARS_PATTERN = '/[\x00-\x20\x7F\\\\]/';

	/**
	 * Returns the value when it can be shown as an external link, null otherwise.
	 * The value is never repaired: only surrounding whitespace is stripped.
	 *
	 * @param string|null $value Raw value, usually taken from a session field.
	 * @return string|null
	 */
	public static function getSafeExternalUrl(?string $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		$value = trim($value);
		if ($value === '')
		{
			return null;
		}

		if (preg_match(self::FORBIDDEN_CHARS_PATTERN, $value))
		{
			return null;
		}

		if (!preg_match('~^https?://~i', $value))
		{
			return null;
		}

		$uri = new Uri($value);

		if (!in_array($uri->getScheme(), self::ALLOWED_SCHEMES, true))
		{
			return null;
		}

		if ($uri->getHost() === '')
		{
			return null;
		}

		return $value;
	}

	/**
	 * Returns the host of a value accepted by getSafeExternalUrl, empty string otherwise.
	 *
	 * @param string|null $value Raw value, usually taken from a session field.
	 * @return string
	 */
	public static function getSafeHost(?string $value): string
	{
		$safeUrl = self::getSafeExternalUrl($value);
		if ($safeUrl === null)
		{
			return '';
		}

		return (new Uri($safeUrl))->getHost();
	}
}
