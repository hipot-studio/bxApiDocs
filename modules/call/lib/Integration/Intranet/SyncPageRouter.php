<?php

declare(strict_types=1);

namespace Bitrix\Call\Integration\Intranet;

use Bitrix\Call\Settings;
use Bitrix\Main\Context;

/**
 * Resolves a component page of the intranet /sync/ section by the requested path and holds the rule
 * of whether the calls page is available.
 *
 * Input is a request path already url-decoded and normalized by the kernel: without the query string,
 * with collapsed repeated slashes and with index.php appended to a directory address
 * (Bitrix\Main\HttpRequest::getRequestedPage()). The section lives under SITE_DIR of its site, as its route
 * does: the path is read from the site dir given or, by default, the one of the site of the current context,
 * and a redirect stays under it. The address constants are the addresses of a site at the root.
 *
 * The class is called both by the component of the section and by the section files of the intranet
 * module, so the availability rule lives here and nowhere else.
 */
class SyncPageRouter
{
	public const PAGE_LANDING = '';
	public const PAGE_CALLS = 'calls';
	public const SECTION_PATH = '/sync/';
	public const CALLS_PATH = '/sync/calls/';

	private const INDEX_SUFFIX = '/index.php';
	private const ROOT_SITE_DIR = '/';

	/**
	 * An address of the section and the page answering it; the landing answers every address not listed.
	 *
	 * The address is matched by the beginning of the path, so a nested address stays on the page of its
	 * list. The trailing slash holds the boundary of the prefix: without it /sync/callsomething/ would
	 * match the calls page too. The address is the key because PHP casts a numeric string key to int.
	 * The first matching entry wins, so a page nested under another one has to be declared before it.
	 */
	private const PAGES_BY_PATH = [
		self::CALLS_PATH => self::PAGE_CALLS,
	];

	/**
	 * Returns the name of the component page serving the requested path.
	 *
	 * @param string $requestedPage
	 * @param string|null $siteDir SITE_DIR of the site the path belongs to; the site of the current context
	 *     when omitted
	 * @return string
	 */
	public static function resolvePage(string $requestedPage, ?string $siteDir = null): string
	{
		$sitePath = self::cutSiteDir($requestedPage, self::resolveSiteDir($siteDir));
		if ($sitePath === null)
		{
			return self::PAGE_LANDING;
		}

		return self::matchPage(self::normalize($sitePath));
	}

	/**
	 * Returns the path to redirect the request to, or null when the path needs no redirect.
	 *
	 * Only an address of the calls page is redirected: an unavailable one goes to the section of the same
	 * site, an available one gets its trailing slash. Every other address, inside the section or not,
	 * is left alone. The returned path is percent-encoded segment by segment, so it stays a single header
	 * value regardless of what the request carried.
	 *
	 * @param string $requestedPage
	 * @param bool $isCallsPageAvailable
	 * @param string|null $siteDir SITE_DIR of the site the path belongs to; the site of the current context
	 *     when omitted
	 * @return string|null
	 */
	public static function getRedirectPath(
		string $requestedPage,
		bool $isCallsPageAvailable,
		?string $siteDir = null,
	): ?string
	{
		$siteDir = self::resolveSiteDir($siteDir);

		if (self::resolvePage($requestedPage, $siteDir) !== self::PAGE_CALLS)
		{
			return null;
		}

		if (!$isCallsPageAvailable)
		{
			return self::encodePath($siteDir . ltrim(self::SECTION_PATH, '/'));
		}

		if (str_ends_with($requestedPage, '/') || str_ends_with($requestedPage, self::INDEX_SUFFIX))
		{
			return null;
		}

		return self::encodePath($requestedPage) . '/';
	}

	/**
	 * Tells whether the calls page of the section is available to the current user.
	 *
	 * @return bool
	 */
	public static function isCallsPageAvailable(): bool
	{
		return Settings::isSyncPresetEnabled();
	}

	/**
	 * SITE_DIR is kept with a slash on both ends; an empty one, which the kernel defines for a context
	 * without a site, reads as the root.
	 */
	private static function resolveSiteDir(?string $siteDir): string
	{
		$siteDir ??= Context::getCurrent()?->getSiteObject()?->getDir() ?? self::ROOT_SITE_DIR;

		$trimmed = trim($siteDir, '/');

		return $trimmed === '' ? self::ROOT_SITE_DIR : '/' . $trimmed . '/';
	}

	/**
	 * Returns the path as an address of a site at the root, or null for a path outside the site.
	 */
	private static function cutSiteDir(string $requestedPage, string $siteDir): ?string
	{
		if (!str_starts_with($requestedPage, $siteDir))
		{
			return null;
		}

		return '/' . substr($requestedPage, strlen($siteDir));
	}

	private static function matchPage(string $normalized): string
	{
		foreach (self::PAGES_BY_PATH as $path => $page)
		{
			if (str_starts_with($normalized, $path))
			{
				return $page;
			}
		}

		return self::PAGE_LANDING;
	}

	private static function normalize(string $requestedPage): string
	{
		$path = $requestedPage;
		if (str_ends_with($path, self::INDEX_SUFFIX))
		{
			$path = substr($path, 0, -strlen(self::INDEX_SUFFIX)) . '/';
		}

		if (!str_ends_with($path, '/'))
		{
			$path .= '/';
		}

		return $path;
	}

	/**
	 * The input is decoded, so one encoding pass restores the wire form instead of doubling it.
	 */
	private static function encodePath(string $path): string
	{
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}
}
