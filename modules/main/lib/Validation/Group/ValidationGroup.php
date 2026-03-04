<?php

declare(strict_types=1);

namespace Bitrix\Main\Validation\Group;

use Bitrix\Main\ArgumentException;
use BackedEnum;
use Stringable;
use UnitEnum;

final class ValidationGroup
{
	public function __construct(
		private readonly null|string|UnitEnum|Stringable $value = null
	)
	{

	}

	public static function create(mixed $value = null): self
	{
		if ($value instanceof self)
		{
			return $value;
		}

		if ($value === null || is_string($value) || $value instanceof UnitEnum || $value instanceof Stringable)
		{
			return new self($value);
		}

		throw new ArgumentException('Invalid validation group value type', 'value');
	}

	public function isNull(): bool
	{
		return $this->value === null;
	}

	public function isEquals(self $other): bool
	{
		if ($this->value === $other->value)
		{
			return true;
		}

		$selfScalar = $this->toScalar($this->value);
		$otherScalar = $this->toScalar($other->value);

		if ($selfScalar !== null && $otherScalar !== null)
		{
			return $selfScalar === $otherScalar;
		}

		return false;
	}

	private function toScalar(mixed $value): ?string
	{
		if (is_string($value))
		{
			return $value;
		}

		if ($value instanceof BackedEnum)
		{
			return (string)$value->value;
		}

		if ($value instanceof Stringable)
		{
			return (string)$value;
		}

		return null;
	}
}
