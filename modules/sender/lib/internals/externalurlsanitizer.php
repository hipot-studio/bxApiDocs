<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2012 Bitrix
 */

namespace Bitrix\Sender\Internals;

use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteDomainTable;
use Bitrix\Main\Web\Uri;

/**
 * Class ExternalUrlSanitizer
 *
 * Strips the service conversion parameter from a redirect target url when it points to an external host.
 * The parameter is only meaningful for pages of this installation, so it must not leak to external sites.
 *
 * @package Bitrix\Sender\Internals
 */
class ExternalUrlSanitizer
{
	public const CONVERSION_PARAM = 'bx_sender_conversion_id';

	/**
	 * Handles the main OnMailEventMailClickRedirect event.
	 * The handler receives a single associative array parameter and may return ['url' => <modified url>].
	 *
	 * @param array $params Event parameters: ['url' => <target url>, 'isSigned' => <bool>].
	 * @return array|null Modified url wrapped in ['url' => ...] when it actually changed, otherwise null.
	 */
	public static function onMailClickRedirect(array $params)
	{
		$url = (string)($params['url'] ?? '');
		// Fast guard: the parameter must appear as a query key ("?" or "&" followed by the param name
		// and "=") to short-circuit cheaply. A match only inside the path, fragment or another param's
		// value must not trigger the (more expensive) parsing below.
		if ($url === '' || !preg_match('/[?&]' . preg_quote(self::CONVERSION_PARAM, '/') . '=/', $url))
		{
			return null;
		}

		$newUrl = static::removeParamFromExternalUrl($url);
		if ($newUrl === $url)
		{
			// Nothing actually changed (internal/relative host, or stripping did not touch the url):
			// keep the null contract honest instead of returning ['url' => <same value>].
			return null;
		}

		return ['url' => $newUrl];
	}

	/**
	 * Removes the sender conversion parameter from a target url if it points to an external host.
	 * The parameter is only meaningful for pages of this installation, so it must not leak to external sites.
	 * Handles protocol-relative urls ("//host/path", "\\host", "/\host", "\/host") and preserves their form.
	 *
	 * @param string $url Target url.
	 * @return string Url without the parameter for external hosts, otherwise the original url.
	 */
	private static function removeParamFromExternalUrl(string $url): string
	{
		// Protocol-relative urls must be detected before normalization: Main\Web\Uri collapses the
		// leading slashes into "/", which hides the external host. Browsers treat "\" as "/", so the
		// leading pair "//", "/\", "\/" and "\\" all resolve to an external authority.
		$trimmedUrl = ltrim($url);
		$isProtocolRelative = strlen($trimmedUrl) >= 2
			&& ($trimmedUrl[0] === '/' || $trimmedUrl[0] === '\\')
			&& ($trimmedUrl[1] === '/' || $trimmedUrl[1] === '\\');

		if ($isProtocolRelative)
		{
			// Normalize the leading pair to "//" and prepend a temporary scheme so the external host
			// becomes visible. Backslashes in the part after the host are turned into forward slashes
			// only for this temporary parsing url: Main\Web\Uri does not treat "\" as a path delimiter,
			// so a host like "portal.example\path" would otherwise be glued to the path and misread as
			// external. The result is still built from the original $url via stripQueryParam, so the
			// backslashes and the original form are preserved byte-for-byte.
			$uri = new Uri('http://' . str_replace('\\', '/', substr($trimmedUrl, 2)));
		}
		else
		{
			// Normalize backslashes to forward slashes for this temporary parsing url ONLY, to
			// extract the host: Main\Web\Uri does not treat "\" as a path delimiter, so a host like
			// "portal.example\path" would otherwise be glued to the path and misread as external.
			// The scheme is unaffected (it precedes "://"). The result is still built from the
			// original $url via stripQueryParam, so the backslashes and the original form are
			// preserved byte-for-byte.
			$uri = new Uri(str_replace('\\', '/', $url));
		}

		$host = $uri->getHost();

		if ($host === '' || static::isInstallationDomain($host))
		{
			return $url;
		}

		// Strip only the sender conversion parameter from the raw query, leaving the rest of the url
		// (scheme, host, path, other params, fragment, encoding, order and case) byte-for-byte intact.
		// The url must not be reassembled through Uri/http_build_query, which would re-encode values
		// ("+" -> "%20"), collapse repeated params, reindex arrays and normalize the host/path.
		return static::stripQueryParam($url, self::CONVERSION_PARAM);
	}

	/**
	 * Removes every occurrence of a query parameter from a url without touching the rest of the string.
	 * If the parameter is absent, the original url is returned unchanged (no normalization at all).
	 *
	 * @param string $url Source url.
	 * @param string $paramName Parameter name to remove (compared after urldecode).
	 * @return string Url without the given parameter, otherwise the original url.
	 */
	private static function stripQueryParam(string $url, string $paramName): string
	{
		// Split off the fragment first: a "?" located after "#" belongs to the fragment, not the query.
		$fragment = '';
		$beforeFragment = $url;
		$hashPos = strpos($url, '#');
		if ($hashPos !== false)
		{
			$fragment = substr($url, $hashPos);
			$beforeFragment = substr($url, 0, $hashPos);
		}

		$queryPos = strpos($beforeFragment, '?');
		if ($queryPos === false)
		{
			// No query at all: nothing to strip.
			return $url;
		}

		$base = substr($beforeFragment, 0, $queryPos);
		$query = substr($beforeFragment, $queryPos + 1);

		$kept = [];
		$removed = false;
		foreach (explode('&', $query) as $component)
		{
			$eqPos = strpos($component, '=');
			$name = $eqPos === false ? $component : substr($component, 0, $eqPos);
			if (urldecode($name) === $paramName)
			{
				$removed = true;
				continue;
			}

			$kept[] = $component;
		}

		if (!$removed)
		{
			// Parameter not present: return the url exactly as received.
			return $url;
		}

		$newQuery = implode('&', $kept);
		$result = $base;
		if ($newQuery !== '')
		{
			$result .= '?' . $newQuery;
		}

		return $result . $fragment;
	}

	/**
	 * Checks whether the host belongs to this Bitrix installation.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	private static function isInstallationDomain(string $host): bool
	{
		// Normalize to a single representation (lowercased punycode) so an IDN host of this installation
		// matches regardless of whether the target url and the configured domain use Unicode or punycode.
		$host = static::normalizeDomain($host);

		// Only configuration-level sources are trusted here. Context::getServer()->getHttpHost()
		// (i.e. $_SERVER['HTTP_HOST']) is intentionally NOT used: it is request-controlled (a forged
		// Host header), so it must never be treated as "our" domain — otherwise an attacker could
		// send a spoofed Host and have an external url pass as internal, keeping the parameter intact.
		$candidates = [
			defined('BX24_HOST_NAME') ? BX24_HOST_NAME : null,
			Option::get('main', 'server_name'),
		];
		foreach ($candidates as $candidate)
		{
			$candidate = (string)$candidate;
			if ($candidate === '')
			{
				continue;
			}

			if ($host === static::normalizeDomain(static::stripHostPort($candidate)))
			{
				return true;
			}
		}

		// Site domains use suffix semantics in the kernel: SiteTable::getByDomain() and
		// Web\Cookie::getCookieDomain() treat "sub.example.com" as belonging to a "example.com" record.
		// Mirror that here so an own site served on a subdomain is recognized as internal. This is applied
		// ONLY to SiteDomainTable entries (they are site domains, subdomains are expected); the host checks
		// above stay an exact match (they are concrete hosts, not domain suffixes).
		$result = SiteDomainTable::getList(['select' => ['DOMAIN'], 'cache' => ['ttl' => 86400]]);
		while ($row = $result->fetch())
		{
			$domain = static::normalizeDomain(static::stripHostPort((string)$row['DOMAIN']));
			if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain)))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a host for comparison: lowercases it and converts an IDN (non-ascii) host to punycode.
	 * A pure-ascii host skips conversion entirely (cheap comparisons stay cheap). If the punycode
	 * conversion fails, the original lowercased host is returned so the check never breaks.
	 *
	 * @param string $host Host name.
	 * @return string Normalized host.
	 */
	private static function normalizeDomain(string $host): string
	{
		$host = mb_strtolower($host);
		if ($host === '' || !preg_match('/[^a-z0-9.\-]/', $host))
		{
			return $host;
		}

		$encoded = \CBXPunycode::ToASCII($host, $encodingErrors);
		if ($encoded === false || !empty($encodingErrors))
		{
			return $host;
		}

		return mb_strtolower($encoded);
	}

	/**
	 * Drops the ":port" suffix from a host, keeping IPv6 literals intact.
	 * The port is removed only when it follows a "]" (bracketed IPv6) or when the host has a single colon
	 * ("host:port"). A bare IPv6 literal ("::1") is left untouched.
	 *
	 * @param string $host Host, possibly with a port.
	 * @return string Host without the port.
	 */
	private static function stripHostPort(string $host): string
	{
		if (str_starts_with($host, '['))
		{
			// Bracketed IPv6 literal "[...]" optionally followed by ":port".
			$closingPos = strpos($host, ']');
			if ($closingPos !== false)
			{
				return substr($host, 0, $closingPos + 1);
			}

			return $host;
		}

		$firstColon = strpos($host, ':');
		if ($firstColon !== false && strpos($host, ':', $firstColon + 1) === false)
		{
			// Exactly one colon: treat it as a "host:port" separator.
			return substr($host, 0, $firstColon);
		}

		return $host;
	}
}
