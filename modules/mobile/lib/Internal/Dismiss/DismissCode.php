<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dismiss;

/**
 * A code of a dismissed element, as the list holds it. \CUserOptions lowercases option names on
 * write, so the rule folds the case instead of rejecting it: codes that worked before the rename
 * keep matching after it.
 */
final class DismissCode implements \Stringable
{
	public const MAX_LENGTH = 50;

	private function __construct(
		private readonly string $value,
	)
	{
	}

	public static function tryFrom(string $code): ?self
	{
		if (preg_match('/^[A-Za-z0-9_-]{1,' . self::MAX_LENGTH . '}$/D', $code) !== 1)
		{
			return null;
		}

		return new self(mb_strtolower($code));
	}

	public static function normalize(string $code): ?string
	{
		return self::tryFrom($code)?->value;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
