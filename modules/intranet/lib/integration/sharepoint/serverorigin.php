<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Sharepoint;

use Bitrix\Main\ArgumentException;

final class ServerOrigin
{
	private const DEFAULT_PORTS = [
		'http' => 80,
		'https' => 443,
	];

	private function __construct(
		private readonly string $scheme,
		private readonly string $host,
		private readonly int $port,
		private readonly bool $isIpv6,
	)
	{
	}

	public static function fromAllowListEntry(string $value): self
	{
		$parts = self::parseUrl($value);
		$unexpectedParts = array_intersect(['user', 'pass', 'path', 'query', 'fragment'], array_keys($parts));
		if ($unexpectedParts !== [])
		{
			throw new ArgumentException('An allowlist entry must contain an origin only.');
		}

		return self::fromParts($parts);
	}

	public static function fromUrl(string|array $url): self
	{
		$parts = is_string($url) ? self::parseUrl($url) : $url;

		if (array_key_exists('user', $parts) || array_key_exists('pass', $parts))
		{
			throw new ArgumentException('URL userinfo is not allowed.');
		}

		return self::fromParts($parts);
	}

	public function toString(): string
	{
		$host = $this->isIpv6 ? '[' . $this->host . ']' : $this->host;
		$port = $this->port === self::DEFAULT_PORTS[$this->scheme] ? '' : ':' . $this->port;

		return $this->scheme . '://' . $host . $port;
	}

	private static function parseUrl(string $url): array
	{
		if (
			$url === ''
			|| trim($url) !== $url
			|| preg_match('/[\\x00-\\x20\\x7f\\\\]/', $url) === 1
			|| preg_match('/\\Ahttps?:\\/\\//i', $url) !== 1
		)
		{
			throw new ArgumentException('Malformed HTTP URL.');
		}

		$parts = parse_url($url);
		if (!is_array($parts))
		{
			throw new ArgumentException('Malformed HTTP URL.');
		}

		return $parts;
	}

	private static function fromParts(array $parts): self
	{
		$scheme = $parts['scheme'] ?? null;
		$host = $parts['host'] ?? null;

		if (!is_string($scheme) || !is_string($host) || $host === '')
		{
			throw new ArgumentException('URL scheme and host are required.');
		}

		$scheme = strtolower($scheme);
		if (!isset(self::DEFAULT_PORTS[$scheme]))
		{
			throw new ArgumentException('Only HTTP and HTTPS origins are allowed.');
		}

		[$host, $isIpv6] = self::normalizeHost($host);
		$port = self::normalizePort($parts['port'] ?? self::DEFAULT_PORTS[$scheme]);

		return new self($scheme, $host, $port, $isIpv6);
	}

	private static function normalizeHost(string $host): array
	{
		if (str_starts_with($host, '[') || str_ends_with($host, ']'))
		{
			if (!str_starts_with($host, '[') || !str_ends_with($host, ']'))
			{
				throw new ArgumentException('Malformed IPv6 host.');
			}

			$address = substr($host, 1, -1);
			$binaryAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
				? inet_pton($address)
				: false
			;
			if ($binaryAddress === false)
			{
				throw new ArgumentException('Malformed IPv6 host.');
			}

			return [strtolower((string)inet_ntop($binaryAddress)), true];
		}

		if (str_ends_with($host, '..'))
		{
			throw new ArgumentException('Malformed DNS host.');
		}

		$host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
		if ($host === '' || str_contains($host, ':') || str_contains($host, '%'))
		{
			throw new ArgumentException('Malformed host.');
		}

		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			return [$host, false];
		}

		if (preg_match('/^[0-9.]+$/', $host) === 1)
		{
			throw new ArgumentException('Ambiguous numeric host.');
		}

		if (strlen($host) > 253)
		{
			throw new ArgumentException('DNS host is too long.');
		}

		foreach (explode('.', $host) as $label)
		{
			if (
				$label === ''
				|| strlen($label) > 63
				|| preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1
			)
			{
				throw new ArgumentException('Malformed DNS host.');
			}
		}

		return [$host, false];
	}

	private static function normalizePort(mixed $port): int
	{
		if (is_string($port) && preg_match('/^[0-9]+$/', $port) === 1)
		{
			$port = (int)$port;
		}

		if (!is_int($port) || $port < 1 || $port > 65535)
		{
			throw new ArgumentException('URL port is out of range.');
		}

		return $port;
	}
}
