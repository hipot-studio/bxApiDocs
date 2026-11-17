<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/**
 * Strict subset of HTTPS origins: an ASCII DNS name or a canonical IPv4 address with an optional
 * port. Anything that could be read in more than one way is rejected instead of being repaired.
 */
final class ClientOriginValidator
{
	private const DEFAULT_PORT = 443;
	private const MAX_HOST_LENGTH = 253;
	private const MAX_LABEL_LENGTH = 63;

	public static function normalize(mixed $value): ?string
	{
		if (
			!is_string($value)
			|| !preg_match('/\Ahttps:\/\/([a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z/iD', $value, $matches)
		)
		{
			return null;
		}

		$host = strtolower($matches[1]);
		$port = isset($matches[2]) ? (int)$matches[2] : self::DEFAULT_PORT;
		if ($port > 65535 || !self::isHost($host))
		{
			return null;
		}

		return 'https://' . $host . ($port === self::DEFAULT_PORT ? '' : ':' . $port);
	}

	private static function isHost(string $host): bool
	{
		if (strlen($host) > self::MAX_HOST_LENGTH)
		{
			return false;
		}

		// A host of digits and dots only is an address; its shortened and octal forms are not accepted.
		return preg_match('/\A[0-9.]+\z/D', $host) ? self::isIpv4($host) : self::isDnsName($host);
	}

	private static function isIpv4(string $host): bool
	{
		$octets = explode('.', $host);
		if (count($octets) !== 4)
		{
			return false;
		}
		foreach ($octets as $octet)
		{
			if (!preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $octet) || (int)$octet > 255)
			{
				return false;
			}
		}

		return true;
	}

	private static function isDnsName(string $host): bool
	{
		$labels = explode('.', $host);
		foreach ($labels as $label)
		{
			if (
				strlen($label) > self::MAX_LABEL_LENGTH
				|| !preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)
				|| str_starts_with($label, 'xn--')
			)
			{
				return false;
			}
		}

		// A numeric or hex last label would make the name parse as an address in some resolvers.
		$last = end($labels);

		return preg_match('/[a-z]/', $last) === 1 && !preg_match('/\A0x[0-9a-f]*\z/D', $last);
	}
}
