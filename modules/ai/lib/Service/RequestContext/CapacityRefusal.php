<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

/**
 * Cue-T is overloaded: a 503 of the ingress or of pool admission, or a 429 of its own slots and
 * queue. Cue-T has no request rate limit of its own, so both mean "come back later"; nothing is
 * sold for it, and Retry-After says when.
 *
 * Only Cue-T's own codes count. A 503 naming another code (an internal error, a stopping node, a
 * disabled route) and a 429 passed through from a provider are technical errors, not overload.
 * A 503 of the ingress is the one exception: it comes as plain text without a code.
 * Money (402) is AccessRefusal's business.
 *
 * Parsing only: nothing is logged, retried or decided here.
 */
final class CapacityRefusal
{
	/** 503 */
	public const KIND_CAPACITY = 'capacity';
	/** 429 */
	public const KIND_RATE = 'rate';

	public const RETRY_SAFE_NO_SEND = 'safe_no_send';

	/** Cue-T answers no longer than this, a bigger value comes from a provider and is cut. */
	public const MAX_RETRY_AFTER_SECONDS = 86400;

	private const CAPACITY_CODES = [
		'pool_admission_soft',
		'pool_admission_closed',
		'policy_transition',
		'deadline_unreachable',
		'no_slot',
		'large_body_capacity_exhausted',
	];
	private const RATE_CODES = ['no_slot', 'slot_wait_timeout', 'queue_wait_expired', 'pool_busy'];

	private function __construct(
		/** capacity|rate: which status Cue-T answered with, both are overload. */
		public readonly string $kind,
		/** Cue-T's code; null for the plain text 503 of the ingress. */
		public readonly ?string $reason,
		public readonly ?int $retryAfterSeconds,
		/**
		 * Pool path only: whether the request may be sent again. Retrying is decided by this together
		 * with the code, since safe_no_send also comes with refusals that a retry does not change.
		 */
		public readonly ?string $retryDisposition = null,
	)
	{
	}

	public static function fromHttpResponse(int $status, string|false $body, ?string $retryAfterHeader): ?self
	{
		$decoded = is_string($body) ? self::decode($body) : null;
		$retryAfter = self::parseRetryAfter($retryAfterHeader);

		if ($status === 503 && $decoded === null)
		{
			return new self(self::KIND_CAPACITY, null, $retryAfter);
		}

		$codes = match ($status)
		{
			503 => self::CAPACITY_CODES,
			429 => self::RATE_CODES,
			default => [],
		};

		$code = self::errorCode($decoded);
		if (!in_array($code, $codes, true))
		{
			return null;
		}

		return new self(
			$status === 503 ? self::KIND_CAPACITY : self::KIND_RATE,
			$code,
			$retryAfter,
			self::retryDisposition($decoded),
		);
	}

	/**
	 * The error of a task callback: the raw JSON body, the decoded body or the error object itself,
	 * with the Retry-After header of the callback when it came. The callback has no status, so the
	 * code decides; no_slot there is the 429 one.
	 */
	public static function fromErrorPayload(mixed $error, ?string $retryAfterHeader = null): ?self
	{
		if (is_string($error))
		{
			$error = self::decode($error);
		}

		if (!is_array($error))
		{
			return null;
		}

		$code = is_array($error['error'] ?? null) ? ($error['error']['code'] ?? null) : ($error['code'] ?? null);
		$retryAfter = self::parseRetryAfter($retryAfterHeader);

		if (in_array($code, self::RATE_CODES, true))
		{
			return new self(self::KIND_RATE, $code, $retryAfter);
		}

		if (in_array($code, self::CAPACITY_CODES, true))
		{
			return new self(self::KIND_CAPACITY, $code, $retryAfter);
		}

		return null;
	}

	/**
	 * Retry-After is either delay-seconds or an HTTP date (RFC 9110); a date in the past means now.
	 * Cue-T sends seconds, the date only reaches us from a provider.
	 */
	private static function parseRetryAfter(?string $header): ?int
	{
		$value = trim((string)$header);

		if ($value === '')
		{
			return null;
		}

		if (preg_match('/^[0-9]+$/D', $value) === 1)
		{
			return min((int)$value, self::MAX_RETRY_AFTER_SECONDS);
		}

		$date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
		if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $value)
		{
			return null;
		}

		return min(max(0, $date->getTimestamp() - time()), self::MAX_RETRY_AFTER_SECONDS);
	}

	private static function errorCode(?array $body): mixed
	{
		return is_array($body['error'] ?? null) ? ($body['error']['code'] ?? null) : null;
	}

	private static function retryDisposition(?array $body): ?string
	{
		$value = $body['retry_disposition'] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	private static function decode(string $body): ?array
	{
		try
		{
			$decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}
}
