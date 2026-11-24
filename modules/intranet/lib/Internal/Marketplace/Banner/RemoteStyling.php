<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner;

final class RemoteStyling
{
	/**
	 * @return array{
	 *     backgroundImageUrl: string|null,
	 *     textColor: string|null,
	 *     buttonBackgroundColor: string|null,
	 *     buttonTextColor: string|null
	 * }
	 */
	public static function normalize(array $data): array
	{
		return [
			'backgroundImageUrl' => self::normalizeImageUrl($data['backgroundImageUrl'] ?? null),
			'textColor' => self::normalizeColor($data['textColor'] ?? null),
			'buttonBackgroundColor' => self::normalizeColor($data['buttonBackgroundColor'] ?? null),
			'buttonTextColor' => self::normalizeColor($data['buttonTextColor'] ?? null),
		];
	}

	private static function normalizeColor(mixed $value): ?string
	{
		return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1
			? strtolower($value)
			: null;
	}

	private static function normalizeImageUrl(mixed $value): ?string
	{
		if (
			!is_string($value)
			|| $value === ''
			|| strlen($value) > 2048
			|| preg_match('~[^\x21-\x7e]|[\\\\\'"<>]~', $value) === 1
		)
		{
			return null;
		}

		$parts = parse_url($value);
		if (
			$parts === false
			|| strtolower($parts['scheme'] ?? '') !== 'https'
			|| !isset($parts['host'])
			|| array_key_exists('user', $parts)
			|| array_key_exists('pass', $parts)
			|| (isset($parts['port']) && $parts['port'] !== 443)
			|| preg_match('~^https://[^/?#]*:(?:[/?#]|$)~i', $value) === 1
		)
		{
			return null;
		}

		$host = rtrim($parts['host'], '.');
		if (
			strlen($host) > 253
			|| preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*'
				. '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host) !== 1
			|| str_ends_with($parts['host'], '..')
			|| preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)$/iD', $host) === 1
		)
		{
			return null;
		}

		return $value;
	}
}
