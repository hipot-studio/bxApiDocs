<?php

declare(strict_types= 1);

namespace Bitrix\Intranet\Public\Dto\User\Absence;

final readonly class ActiveVacationResult
{
	private function __construct(
		public bool $trusted,
		public ?ActiveVacation $vacation,
	)
	{
	}

	public static function trusted(?ActiveVacation $vacation): self
	{
		return new self(true, $vacation);
	}

	public static function untrusted(): self
	{
		return new self(false, null);
	}
}
