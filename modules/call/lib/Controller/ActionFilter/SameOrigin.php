<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Web\Uri;

/**
 * Rejects a state-changing call that a browser made from another site.
 *
 * The Csrf filter of the core is declared for the ajax scope only, so a REST action open to a guest has no
 * token behind it: the guest session is recognised by its cookie alone. Restricting the method to POST
 * takes away the top-level GET navigation, but an ordinary cross-site form posts just as well wherever the
 * cookie is not SameSite-bound.
 *
 * Origin is what separates the two: a browser always sends it on a cross-site POST and a page cannot forge
 * or drop it. Native clients either send no Origin, which is why an absent header passes, or a bare host
 * with no scheme (iOS), which no browser ever writes. This is a defence against a browser, not against a
 * client holding a stolen cookie; nothing but taking the action away from guests would help there.
 */
class SameOrigin extends Base
{
	public const ERROR_FOREIGN_ORIGIN = 'foreign_origin';

	private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

	public function onBeforeAction(Event $event): ?EventResult
	{
		$request = $this->action->getController()->getRequest();

		$origin = trim((string)$request->getHeader('Origin'));
		if ($origin === '')
		{
			return null;
		}

		if ($this->isOwnOrigin($origin, (string)$request->getServer()->getHttpHost()))
		{
			return null;
		}

		$this->addError(new Error('Request origin is not allowed', self::ERROR_FOREIGN_ORIGIN));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	/**
	 * Is this Origin our own host.
	 *
	 * The raw Host header is taken rather than HttpRequest::getHttpHost(), which drops the port, so that a
	 * portal answering on a port of its own still matches its own pages. Both sides are compared as host and
	 * port with the default port dropped: behind a TLS-terminating proxy Host carries an explicit :443 that a
	 * browser never writes into Origin. Host is no less trustworthy here than the check itself: a cross-site
	 * page chooses the Origin it is given, never the Host.
	 *
	 * Which port counts as default differs between the two sides, and the asymmetry is the point. An Origin
	 * names its own scheme, so only that scheme's default is dropped from it. Host names no scheme, and both
	 * of ours are accepted - the scheme is not what the check is about, a foreign site differs by host, and a
	 * portal behind a TLS-terminating proxy cannot always tell which of the two the browser used - so a port
	 * that is the default of either is dropped from it.
	 *
	 * An Origin without a scheme is a bare host[:port], as the iOS client writes it. A browser never sends that
	 * form, so it is compared the way Host is, with 80 and 443 dropped on both sides.
	 *
	 * An Origin that is neither an http(s) nor a bare authority matches nothing: the header comes from the
	 * outside and is no promise of a well-formed URL.
	 */
	private function isOwnOrigin(string $origin, string $httpHost): bool
	{
		if ($httpHost === '')
		{
			return false;
		}

		$originAuthority = str_contains($origin, '://')
			? $this->originAuthority($origin)
			: $this->hostAuthority($origin)
		;
		$ownAuthority = $this->hostAuthority($httpHost);

		return $originAuthority !== '' && $originAuthority === $ownAuthority;
	}

	/**
	 * `https://portal:80` keeps its port: to a browser that is a different origin than the portal on 443, so
	 * dropping :80 there would let a page served beside the portal, on the same name but its own listener,
	 * pass the filter as the portal itself.
	 */
	private function originAuthority(string $origin): string
	{
		$parts = $this->bareAuthority($origin);
		if ($parts === null)
		{
			return '';
		}

		[$scheme, $host, $port] = $parts;

		return $port === self::DEFAULT_PORTS[$scheme] ? $host : $host . ':' . $port;
	}

	private function hostAuthority(string $httpHost): string
	{
		$parts = $this->bareAuthority('https://' . $httpHost);
		if ($parts === null)
		{
			return '';
		}

		[, $host, $port] = $parts;

		return in_array($port, self::DEFAULT_PORTS, true) ? $host : $host . ':' . $port;
	}

	/**
	 * @return array{0: string, 1: string, 2: int}|null scheme, host and port, or null when the url is not a
	 *                                                  bare http(s) authority
	 */
	private function bareAuthority(string $url): ?array
	{
		$uri = new Uri($url);

		$scheme = $uri->getScheme();
		$host = $uri->getHost();
		if ($host === '' || !isset(self::DEFAULT_PORTS[$scheme]))
		{
			return null;
		}

		$isBareAuthority = $uri->getUserInfo() === ''
			&& $uri->getPath() === ''
			&& $uri->getQuery() === ''
			&& $uri->getFragment() === ''
		;
		if (!$isBareAuthority)
		{
			return null;
		}

		return [$scheme, $host, $uri->getPort()];
	}
}
