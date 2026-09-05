<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Filters;

use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Verifies the signature of an incoming vibeoffice webhook (P5.T1, API-WEBHOOK).
 *
 * The platform signs each webhook with the shared `webhook_secret`:
 *
 *   X-VO-Signature == "v1=" + hex(hmac_sha256(webhook_secret, "{X-VO-Timestamp}.{raw_body}"))
 *
 * The signature is computed over the **raw** request body (`php://input`) before any
 * JSON parsing/re-encoding — re-serialising the JSON would change byte-for-byte content
 * and break the HMAC (integration-guide §11). The compare is constant-time (`hash_equals`)
 * and the timestamp must be within ±300s of now to reject replays.
 *
 * This filter is fully isolated from the OnlyOffice webhook authorization (JWT Bearer):
 * the vibeoffice webhook endpoint installs its own prefilters and does not share any
 * logic with {@see \Bitrix\Disk\Document\OnlyOffice\Filters\Authorization}.
 *
 * An empty `webhook_secret` makes every HMAC reduce to a forgeable signature, so the
 * webhook is denied outright in that case (defense-in-depth, mirroring the egress denial).
 */
class WebhookSignatureCheck extends ActionFilter\Base
{
	public const HEADER_EVENT = 'X-VO-Event';
	public const HEADER_DELIVERY = 'X-VO-Delivery';
	public const HEADER_TIMESTAMP = 'X-VO-Timestamp';
	public const HEADER_SIGNATURE = 'X-VO-Signature';

	private const SIGNATURE_PREFIX = 'v1=';

	/** Maximum allowed clock skew between the platform timestamp and now (seconds). */
	private const MAX_SKEW = 300;

	private string $secret;

	public function __construct(string $secret)
	{
		parent::__construct();

		$this->secret = $secret;
	}

	public function onBeforeAction(Event $event)
	{
		if ($this->secret === '')
		{
			return $this->deny('Vibeoffice webhook is not configured (empty webhook secret).');
		}

		$request = Context::getCurrent()->getRequest();

		$timestamp = (int)$request->getHeader(self::HEADER_TIMESTAMP);
		$signature = (string)$request->getHeader(self::HEADER_SIGNATURE);

		if ($timestamp <= 0 || $signature === '')
		{
			return $this->deny('Missing webhook signature headers.');
		}

		if (abs(time() - $timestamp) > self::MAX_SKEW)
		{
			return $this->deny('Webhook timestamp is out of the allowed window.');
		}

		// Signature is computed over the RAW body BEFORE any parsing/re-encoding.
		$rawBody = (string)(new JsonPayload())->getRaw();

		$expected = self::sign($this->secret, $timestamp, $rawBody);
		if (!hash_equals($expected, $signature))
		{
			return $this->deny('Invalid webhook signature.');
		}

		return null;
	}

	/**
	 * Computes the canonical webhook signature value (including the `v1=` prefix):
	 *
	 *   sign = "v1=" + hex(hmac_sha256(secret, "{timestamp}.{rawBody}"))
	 */
	public static function sign(string $secret, int $timestamp, string $rawBody): string
	{
		$canonical = $timestamp . '.' . $rawBody;

		return self::SIGNATURE_PREFIX . hash_hmac('sha256', $canonical, $secret);
	}

	private function deny(string $message): EventResult
	{
		Context::getCurrent()->getResponse()->setStatus('401 Unauthorized');
		$this->addError(new Error($message, 'bad_signature'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
