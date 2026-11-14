<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

final class AuditTransactionOutcome
{
	private function __construct(private readonly bool $successful, private readonly mixed $value)
	{
	}

	public static function success(mixed $value = null): self
	{
		return new self(true, $value);
	}

	public static function failure(mixed $value = null): self
	{
		return new self(false, $value);
	}

	public function isSuccess(): bool
	{
		return $this->successful;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}
}
