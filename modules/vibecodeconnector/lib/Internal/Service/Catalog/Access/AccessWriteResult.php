<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

final class AccessWriteResult
{
	private function __construct(
		public readonly bool $changed,
		public readonly bool $revoked,
	) {}

	public static function unchanged(): self
	{
		return new self(false, false);
	}

	public static function changed(bool $revoked): self
	{
		return new self(true, $revoked);
	}
}
