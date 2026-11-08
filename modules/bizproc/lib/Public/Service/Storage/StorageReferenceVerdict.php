<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Storage;

final class StorageReferenceVerdict
{
	private function __construct(
		public readonly bool $allowed,
		public readonly ?string $reason = null,
	) {}

	public static function allow(): self
	{
		return new self(true);
	}

	public static function reject(string $reason): self
	{
		return new self(false, $reason);
	}
}
