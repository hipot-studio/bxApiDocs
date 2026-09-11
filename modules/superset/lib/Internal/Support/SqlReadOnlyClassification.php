<?php

namespace Bitrix\Superset\Internal\Support;

/**
 * Immutable verdict returned by {@see SqlReadOnlyClassifier::classify()}.
 *
 * When the SQL is rejected, {@see self::$reason} carries a human-readable,
 * user-facing message describing why. That message is surfaced verbatim to the
 * caller (SqlLabService turns it into an error Result, which reaches the
 * execute_sql tool as an McpException), so it must read as a complete sentence.
 */
final class SqlReadOnlyClassification
{
	private function __construct(
		public readonly bool $isReadOnly,
		public readonly ?string $reason,
	)
	{
	}

	public static function allow(): self
	{
		return new self(true, null);
	}

	public static function reject(string $reason): self
	{
		return new self(false, $reason);
	}
}
