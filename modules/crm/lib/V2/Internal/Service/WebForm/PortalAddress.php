<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Web\Uri;

/**
 * The address a request arrived at: the host an answer has to lead back to, and the port that host
 * answers on, written as `:8443` or as nothing at all.
 *
 * The host is taken off the request rather than off the raw server value: the request parses the
 * `Host` header through `Uri` and trims it, so a path or a stray dot cannot reach an answer.
 * Parsing does not clean the header, though - `parse_url()` keeps the characters that end a string
 * literal and turns a control character into an underscore - so the parsed host is measured against
 * the characters a host name is made of, and a request whose host is anything else is a request
 * without an address.
 *
 * The port has to reach an answer - a portal answering on a port of its own opens nothing on the
 * standard one - and the request drops it, so the port is read here off the same header.
 * `SERVER_PORT` is not that port: behind a balancer terminating TLS outside, it is the port PHP was
 * reached on rather than the one a browser can open.
 *
 * @internal
 */
final readonly class PortalAddress
{
	/**
	 * The characters a host name is made of.
	 *
	 * `parse_url()` leaves `'`, `"`, `\`, `<` and `>` in the host it gives back and a second
	 * parse does not take them out, while the embed snippet of a form puts the host into a string
	 * literal of JavaScript published on a site of somebody else. A host is therefore what this
	 * says it is, or there is no address to answer with.
	 *
	 * The underscore is in the set although a name of a host is not supposed to carry one: stands
	 * named that way exist, none of the characters that end a string literal of JavaScript is among
	 * them, and a portal answering on such a name would otherwise lose the address of every link of
	 * this response. A name outside ASCII is not: a browser sends an international name as punycode,
	 * which this set already accepts.
	 */
	private const HOST_NAME_PATTERN = '/^[a-z0-9._\-]+$/iD';

	/** An address literal of IPv6 arrives in brackets and is the one host written with colons. */
	private const IPV6_HOST_PATTERN = '/^\[[0-9a-f:.]+\]$/iD';

	public function __construct(
		public string $host,
		public string $port = '',
	)
	{
	}

	public static function of(HttpRequest $request): self
	{
		$host = (string)$request->getHttpHost();
		if (!self::isHostName($host))
		{
			return new self('');
		}

		return new self($host, self::readPort($request, $host));
	}

	private static function isHostName(string $host): bool
	{
		return preg_match(self::HOST_NAME_PATTERN, $host) === 1
			|| preg_match(self::IPV6_HOST_PATTERN, $host) === 1
		;
	}

	/**
	 * The standard port of the scheme is left out, and so is the port of a header carrying anything
	 * besides the host and that port: `Uri` reads a port out of a poisoned header as readily as out
	 * of a sound one, and only the header that is the host and the port is trusted with it.
	 */
	private static function readPort(HttpRequest $request, string $host): string
	{
		$scheme = $request->isHttps() ? 'https' : 'http';
		$header = (string)$request->getServer()->getHttpHost();

		$port = (new Uri($scheme . '://' . $header))->getPort();
		if ($port === null || $port === ($scheme === 'https' ? 443 : 80))
		{
			return '';
		}

		return strtolower(trim($header, "\t\r\n\0 .")) === $host . ':' . $port ? ':' . $port : '';
	}
}
