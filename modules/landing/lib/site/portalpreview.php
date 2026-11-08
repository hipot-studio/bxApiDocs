<?php

declare(strict_types=1);

namespace Bitrix\Landing\Site;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Main\Application;

/**
 * Owner of the cloud form of the preview url: the route /sites/site/<id>/preview/<page id>/ of the
 * "Sites" section, served to an authorized user instead of a signed public address.
 */
final class PortalPreview
{
	/** Placeholders are the ones the SEF templates of landing.start use. */
	public const DEFAULT_ROUTE = '/sites/site/#site_show#/preview/#landing_edit#/';

	/** Test seam: locally and on a VM the cloud branch never runs (LANDING_DISABLE_CLOUD). */
	private static ?bool $cloudOverride = null;

	private static ?string $route = null;

	/**
	 * Must the preview link of this row become the route of the section? Preview mode itself is not
	 * checked here: the caller knows it.
	 * @param bool $rowIsB24 Per row B24 flag (already lowered for TYPE = 'SMN').
	 * @param bool $cloudDisabled Value of Manager::isCloudDisable() calculated by the caller.
	 * @param string|null $siteType Type of the site row, empty means the scope of the process.
	 * @return bool
	 */
	public static function isCloud(bool $rowIsB24, bool $cloudDisabled, ?string $siteType = null): bool
	{
		$cloud = self::$cloudOverride ?? ($rowIsB24 && !$cloudDisabled);

		// the override replaces the pair of flags only, the scope is checked in any case
		return $cloud && Type::isPublicScope($siteType);
	}

	/**
	 * Replaces the pair of cloud flags for the whole process. Null removes the override.
	 * @param bool|null $cloud Value the predicate takes instead of the flags.
	 * @return void
	 */
	public static function overrideCloud(?bool $cloud): void
	{
		self::$cloudOverride = $cloud;
	}

	/**
	 * Sets the route of the section the preview belongs to. Null returns the default one.
	 * @param string|null $template Route template with the placeholders of DEFAULT_ROUTE.
	 * @return void
	 */
	public static function setRoute(?string $template): void
	{
		self::$route = $template;
	}

	/**
	 * @return string
	 */
	public static function getRoute(): string
	{
		return self::$route ?? self::DEFAULT_ROUTE;
	}

	/**
	 * Address of the preview of the page.
	 * @param int $siteId Site id.
	 * @param int $landingId Page id.
	 * @param bool $absolute Prepend the portal origin; an unknown host leaves the result relative.
	 * @return string
	 */
	public static function buildUrl(int $siteId, int $landingId, bool $absolute): string
	{
		$path = str_replace(
			['#site_show#', '#landing_edit#'],
			[(string)$siteId, (string)$landingId],
			self::getRoute()
		);

		return ($absolute ? self::getPortalOrigin() : '') . $path;
	}

	/**
	 * Scheme and host of the current portal request, empty when the request carries no host: the
	 * session of the user lives on that host, and no spare one is put in its place.
	 * @return string
	 */
	public static function getPortalOrigin(): string
	{
		$host = (string)Manager::getHttpHost();
		if ($host === '')
		{
			return '';
		}

		$authority = (string)Application::getInstance()->getContext()->getServer()->getHttpHost();

		return (Manager::isHttps() ? 'https' : 'http') . '://' . self::withRequestPort($host, $authority);
	}

	/**
	 * Puts the port of the request authority back to the bare host of Manager::getHttpHost(), which
	 * strips it. Anything but a numeric port of the same host leaves the bare host as is.
	 * @param string $host Bare host (Manager::getHttpHost()).
	 * @param string $authority Raw authority of the request, host[:port].
	 * @return string
	 */
	public static function withRequestPort(string $host, string $authority): string
	{
		$port = mb_substr($authority, mb_strlen($host));
		if (
			mb_strtolower(mb_substr($authority, 0, mb_strlen($host))) === mb_strtolower($host)
			&& preg_match('/^:\d{1,5}$/', $port)
		)
		{
			return $host . $port;
		}

		return $host;
	}

	/**
	 * Page of the address, for the template of the route. The read right is applied by the access
	 * filter of Landing::getList(), and null means the same for "no rights", "no page", "page of
	 * another site" and "non public scope": the address must not tell which of them it is.
	 * @param int $siteId Site id of the address.
	 * @param int $landingId Page id of the address.
	 * @return Landing|null
	 */
	public static function resolvePage(int $siteId, int $landingId): ?Landing
	{
		$landing = Landing::createInstance($landingId, ['skip_blocks' => true]);

		if (
			!$landing->exist()
			|| (int)$landing->getSiteId() !== $siteId
			// the scope filter of getList() is wider than the route: under PAGE it reads VIBE and SMN too
			|| !Type::isPublicScope($landing->getSiteTypeCode())
		)
		{
			return null;
		}

		return $landing;
	}
}
