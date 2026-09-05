<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Filters;

use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Web\Uri;

/**
 * Authorizes the egress endpoint (document.url) that the vibeoffice platform
 * itself pulls to fetch the current document source.
 *
 * The signature scheme is host-side (it is the host that generates and signs its
 * own URL), so it differs from the platform VO1 request signature: the host
 * appends `vo_exp` (unix expiry) and `vo_sig` query params to the URL it puts in
 * `document.url`, and this filter recomputes the HMAC over the same canonical
 * string and constant-time compares it. The signature travels in the query
 * string (not a header) because the platform fetches the URL with a plain GET.
 *
 * The api-secret used to sign never leaves the backend (ADR invariant); it is
 * only consumed here to verify the signature.
 */
class EgressUrlSignatureCheck extends ActionFilter\Base
{
	public const PARAM_EXPIRES = 'vo_exp';
	public const PARAM_SIGNATURE = 'vo_sig';
	public const PARAM_VERSION = 'versionId';

	private string $secret;

	public function __construct(string $secret)
	{
		parent::__construct();

		$this->secret = $secret;
	}

	public function onBeforeAction(Event $event)
	{
		// An empty api-secret makes every HMAC reduce to hmac('', …) — a forgeable,
		// cryptographically meaningless signature. Deny egress outright in that case
		// rather than validate against an empty secret.
		if ($this->secret === '')
		{
			return $this->deny('Vibeoffice egress is not configured (empty api-secret).');
		}

		$request = Context::getCurrent()->getRequest();

		$documentSessionHash = (string)$request->getQuery('documentSessionHash');
		$expires = (int)$request->getQuery(self::PARAM_EXPIRES);
		$signature = (string)$request->getQuery(self::PARAM_SIGNATURE);
		$versionId = (int)$request->getQuery(self::PARAM_VERSION);

		if ($documentSessionHash === '' || $expires <= 0 || $signature === '')
		{
			return $this->deny('Missing egress signature.');
		}

		if ($expires < time())
		{
			return $this->deny('Egress signature is expired.');
		}

		$expected = self::sign($this->secret, $documentSessionHash, $expires, $versionId ?: null);
		if (!hash_equals($expected, $signature))
		{
			return $this->deny('Invalid egress signature.');
		}

		return null;
	}

	private function deny(string $message): EventResult
	{
		Context::getCurrent()->getResponse()->setStatus('403 Forbidden');
		$this->addError(new Error($message));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	/**
	 * Computes the host-side egress signature.
	 *
	 * canonical = join("\n", [documentSessionHash, expires, versionId])  // versionId "" when absent
	 * sig       = hex(hmac_sha256(secret, canonical))
	 *
	 * versionId is bound into the signature so a source URL cannot be replayed to pull
	 * an arbitrary version (and vice versa).
	 */
	public static function sign(string $secret, string $documentSessionHash, int $expires, ?int $versionId = null): string
	{
		if ($secret === '')
		{
			// Signing with an empty secret would yield a forgeable signature; refuse
			// to produce one (keeps URL generation in lockstep with onBeforeAction).
			throw new ArgumentException('Vibeoffice egress secret must not be empty.', 'secret');
		}

		$canonical = implode("\n", [
			$documentSessionHash,
			(string)$expires,
			$versionId === null ? '' : (string)$versionId,
		]);

		return hash_hmac('sha256', $canonical, $secret);
	}

	/**
	 * Appends the expiry and signature query params to an absolute egress URL so
	 * that only the platform (which received the URL via document.url) can pull it
	 * until the TTL elapses. The signature covers the version the URL already encodes.
	 */
	public static function buildSignedUrl(
		string $absoluteUrl,
		string $secret,
		string $documentSessionHash,
		int $ttlSeconds,
		?int $versionId = null
	): string
	{
		$expires = time() + $ttlSeconds;

		$uri = new Uri($absoluteUrl);
		$uri->addParams([
			self::PARAM_EXPIRES => (string)$expires,
			self::PARAM_SIGNATURE => self::sign($secret, $documentSessionHash, $expires, $versionId),
		]);

		return $uri->getUri();
	}
}
