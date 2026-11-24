<?php

declare(strict_types=1);

namespace Bitrix\Mobile\PerfSampling;

use Bitrix\Main\Web\IpAddress;

/**
 * Accepts only an address the app is ready to post its batches to: https, no userinfo, query or fragment,
 * a public host. The app repeats the check itself and drops the collection on any mismatch,
 * so a portal sending an address it would reject only wastes the key.
 */
final class EndpointValidator
{
	public const MAX_LENGTH = 512;

	/**
	 * Printable ASCII only, and none of "?", "#", "@" or "\" anywhere: no query, fragment or userinfo
	 * that parse_url and the HTTP client of the app might split differently.
	 */
	private const FORBIDDEN_CHARACTERS = '/[^\x21-\x7e]|[?#@\\\\]/';
	private const HOSTNAME_LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

	/**
	 * IPv4 ranges no ingest server lives in. Listed explicitly: the reserved ranges of filter_var
	 * differ between PHP versions, and 8.2 lets multicast, CGNAT and the IETF test nets through.
	 */
	private const NON_PUBLIC_IPV4_RANGES = [
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
	];

	public function isValid(string $endpoint): bool
	{
		if (
			$endpoint === ''
			|| strlen($endpoint) > self::MAX_LENGTH
			|| preg_match(self::FORBIDDEN_CHARACTERS, $endpoint)
		)
		{
			return false;
		}

		$parts = parse_url($endpoint);
		if (
			!is_array($parts)
			|| strtolower($parts['scheme'] ?? '') !== 'https'
			|| !isset($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
		)
		{
			return false;
		}

		return $this->isPublicHost(strtolower($parts['host']));
	}

	private function isPublicHost(string $host): bool
	{
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)
		{
			$address = new IpAddress($host);
			foreach (self::NON_PUBLIC_IPV4_RANGES as $range)
			{
				if ($address->matchRange($range))
				{
					return false;
				}
			}

			return true;
		}

		// The product works over IPv4 only, so an IPv6 literal ("[::1]") is not a hostname either and is rejected here.
		return $this->isPublicHostname($host);
	}

	private function isPublicHostname(string $host): bool
	{
		$labels = explode('.', $host);
		if (count($labels) < 2)
		{
			return false;
		}

		foreach ($labels as $label)
		{
			if (!preg_match(self::HOSTNAME_LABEL, $label))
			{
				return false;
			}
		}

		$topLevelDomain = end($labels);
		if ($topLevelDomain === 'localhost')
		{
			return false;
		}

		// A numeric top-level label means an IPv4 address in a short or integer form ("127.1"), not a domain.
		return preg_match('/[a-z]/', $topLevelDomain) === 1;
	}
}
