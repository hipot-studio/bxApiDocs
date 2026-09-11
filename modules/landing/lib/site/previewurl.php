<?php

declare(strict_types=1);

namespace Bitrix\Landing\Site;

use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Main\Application;

/**
 * Owner of the cloud form of the preview url.
 *
 * In the cloud a preview link of a site with its own domain record must live on the portal host
 * and on the publication path (https://<portal>/pub/site/<id>/preview/<hash>/), because the preview
 * hash is signed exactly by that pair. Both url builders take that portal part from here only, so
 * the host of the url and the host of the signature can not diverge. The tail is divided:
 * Landing::getPublicUrl() takes the whole preview base (buildPreviewBase()), while
 * Site::getPublicUrl() takes the site base (buildSiteBase()) and appends the /preview/<hash>/ tail
 * by the common branch of its own - that branch adds the tail to every non published site anyway,
 * and taking a ready tail from here as well would double it.
 *
 * The class is stateless and reads no database: row flags, the "cloud disabled" flag and the path
 * key are passed by the calling builder, which has already calculated them.
 */
final class PreviewUrl
{
	/**
	 * Is the row a cloud site with its own domain record, i.e. the one whose preview link must be
	 * moved to the portal host? Preview mode itself is not checked here: the caller knows it.
	 * @param bool $rowIsB24 Per row B24 flag (already lowered for TYPE = 'SMN').
	 * @param bool $siteHasOwnDomain Site has its own domain record.
	 * @param bool $cloudDisabled Value of Manager::isCloudDisable() calculated by the caller.
	 * @return bool
	 */
	public static function isCloudTarget(bool $rowIsB24, bool $siteHasOwnDomain, bool $cloudDisabled): bool
	{
		return $rowIsB24 && !$cloudDisabled && $siteHasOwnDomain;
	}

	/**
	 * The same predicate multiplied by the preview itself - for the callers which have no own
	 * preview flag (no disableLinkPreview). The preview is either the global mode of the process or
	 * the preview form the caller asks for by itself ($previewForNotActive of Site::getPublicUrl()
	 * on a non published site): the signed tail is bound to the portal host, so whenever the tail
	 * is appended the base has to be the portal one too, whatever the global mode says.
	 * @param bool $rowIsB24 Per row B24 flag (already lowered for TYPE = 'SMN').
	 * @param bool $siteHasOwnDomain Site has its own domain record.
	 * @param bool $cloudDisabled Value of Manager::isCloudDisable() calculated by the caller.
	 * @param bool $previewRequested The caller appends the signed preview tail on its own.
	 * @return bool
	 */
	public static function isCloudPreview(
		bool $rowIsB24,
		bool $siteHasOwnDomain,
		bool $cloudDisabled,
		bool $previewRequested = false
	): bool
	{
		return ($previewRequested || Landing::getPreviewMode())
			&& self::isCloudTarget($rowIsB24, $siteHasOwnDomain, $cloudDisabled);
	}

	/**
	 * Scheme and host of the current portal request - the very same source the preview signature
	 * is built from. Empty string, when the host is unknown (out of a web request): no spare host is
	 * put in its place, because a link built on a foreign host would look whole and would not pass
	 * the check of the signature. The port of the request is kept (see withRequestPort()): the
	 * signature is bound to the bare host, the link has to stay on the authority the visitor uses.
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
	 * Puts the port of the request authority back to the signed host.
	 *
	 * Manager::getHttpHost() strips the port on purpose, and the signature is built from that bare
	 * host - so a portal reached as host:8080 would get a link on the default port. The port is
	 * taken back from the raw authority only when it really belongs to the same host and is a
	 * number; anything else leaves the bare host as is.
	 * @param string $host Bare host the signature is built from (Manager::getHttpHost()).
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
	 * Publication path of the site without the trailing slash, for example /pub/site/2441.
	 * @param int|string $siteKey The very key the caller passes to Site::getPublicHash().
	 * @return string
	 */
	public static function getSitePath(int|string $siteKey): string
	{
		return rtrim(Manager::getPublicationPath($siteKey), '/');
	}

	/**
	 * Base of the site url on the portal host, without the trailing slash.
	 * @param int|string $siteKey The very key the caller passes to Site::getPublicHash().
	 * @param bool $absolute Prepend the portal origin. With an unknown host the origin is empty, so
	 * the result stays relative even here - see getPortalOrigin().
	 * @return string
	 */
	public static function buildSiteBase(int|string $siteKey, bool $absolute): string
	{
		return ($absolute ? self::getPortalOrigin() : '') . self::getSitePath($siteKey);
	}

	/**
	 * Base of the preview url on the portal host, with the trailing slash. The tail of folders and
	 * of the page code is appended by the caller.
	 * @param int|string $siteKey The very key the caller passes to Site::getPublicHash().
	 * @param string $publicHash Preview hash of the site.
	 * @param bool $absolute Prepend the portal origin. With an unknown host the origin is empty, so
	 * the result stays relative even here - see getPortalOrigin().
	 * @return string
	 */
	public static function buildPreviewBase(int|string $siteKey, string $publicHash, bool $absolute): string
	{
		return self::buildSiteBase($siteKey, $absolute) . '/preview/' . $publicHash . '/';
	}
}
