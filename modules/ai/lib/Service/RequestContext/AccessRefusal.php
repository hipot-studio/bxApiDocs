<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

/**
 * Money refusal of Cue-T (402): whose limit ran out and whether it is a limit at all.
 *
 * The body comes in two shapes depending on the path, not on the route: the error code as a string
 * with the rule fields next to it (the queue path), or an object {"error":{"code",...}} with the
 * fields inside (the native path and the task error callback). Both carry the same answer.
 *
 * Parsing only: nothing is logged or decided here, the caller chooses what to show.
 */
final class AccessRefusal
{
	public const HOLDER_USER = 'user';
	public const HOLDER_PORTAL = 'portal';

	private const CODE_QUOTA_EXHAUSTED = 'quota_exhausted';
	private const MONEY_CODES = ['access_blocked', self::CODE_QUOTA_EXHAUSTED, 'portal_restricted'];
	private const QUOTA_REASON = 'QUOTA_EXHAUSTED';
	/** The company windows (DAY_, WEEK_, a future MONTH_) as the vibecode platform is to publish them. */
	private const WINDOW_SUFFIX = '_WINDOW_EXHAUSTED';

	private function __construct(
		public readonly string $code,
		/** user|portal as Cue-T sent it; null for the v1 body without the field. */
		public readonly ?string $scope,
		/** Which side of the rule matched. Informational: the answer never depends on it. */
		public readonly ?string $match,
		public readonly ?string $reason,
	)
	{
	}

	public static function fromHttpResponse(int $status, string|false $body): ?self
	{
		if ($status !== 402 || !is_string($body))
		{
			return null;
		}

		return self::fromDecodedBody(self::decode($body));
	}

	/**
	 * The error of a task callback: the raw JSON body, the decoded body or the error object itself.
	 */
	public static function fromErrorPayload(mixed $error): ?self
	{
		if (is_string($error))
		{
			$decoded = self::decode($error);
			if ($decoded === null)
			{
				return in_array($error, self::MONEY_CODES, true) ? new self($error, null, null, null) : null;
			}

			$error = $decoded;
		}

		if (!is_array($error))
		{
			return null;
		}

		if (array_key_exists('error', $error))
		{
			return self::fromDecodedBody($error);
		}

		return self::fromFields($error['code'] ?? null, $error);
	}

	/**
	 * Whose limit: the employee's one only when Cue-T says so, everything else is the company's —
	 * the v1 body has no scope and is always about the portal.
	 */
	public function getHolder(): string
	{
		return $this->scope === self::HOLDER_USER ? self::HOLDER_USER : self::HOLDER_PORTAL;
	}

	public function isUserLimit(): bool
	{
		return $this->getHolder() === self::HOLDER_USER;
	}

	/**
	 * A limit that a subscription or the next window lifts, as opposed to a block (ACCOUNT_FROZEN,
	 * MANUAL or any reason we do not know), which nothing is sold for.
	 *
	 * Without a reason only the v1 quota_exhausted is a limit. Under a bare portal_restricted the old
	 * gate hides a frozen portal as well as an exhausted one, and the pool form of access_blocked
	 * drops the rule fields: selling there could mean selling to a frozen portal.
	 */
	public function isQuota(): bool
	{
		if ($this->reason === null)
		{
			return $this->code === self::CODE_QUOTA_EXHAUSTED;
		}

		return $this->reason === self::QUOTA_REASON || str_ends_with($this->reason, self::WINDOW_SUFFIX);
	}

	public function isBlock(): bool
	{
		return !$this->isQuota();
	}

	private static function fromDecodedBody(?array $body): ?self
	{
		$error = $body['error'] ?? null;

		if (is_string($error))
		{
			return self::fromFields($error, $body);
		}

		if (is_array($error))
		{
			return self::fromFields($error['code'] ?? null, $error);
		}

		return null;
	}

	private static function fromFields(mixed $code, array $fields): ?self
	{
		if (!in_array($code, self::MONEY_CODES, true))
		{
			return null;
		}

		return new self(
			$code,
			self::optionalString($fields['scope'] ?? null),
			self::optionalString($fields['match'] ?? null),
			self::optionalString($fields['reason'] ?? null),
		);
	}

	private static function optionalString(mixed $value): ?string
	{
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
