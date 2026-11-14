<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation;

final class CandidateExpression
{
	public const EQ = 'eq';
	public const IN = 'in';
	public const IS_NULL = 'isNull';
	public const ALL = 'all';
	public const ANY = 'any';

	private function __construct(
		private readonly string $operator,
		private readonly ?string $field = null,
		private readonly mixed $value = null,
		private readonly array $operands = [],
	)
	{
		if (!in_array($operator, [self::EQ, self::IN, self::IS_NULL, self::ALL, self::ANY], true))
		{
			throw new \InvalidArgumentException('Unknown timeline candidate operator');
		}
		if (in_array($operator, [self::ALL, self::ANY], true))
		{
			if ($operands === [] || array_filter($operands, static fn($item) => !$item instanceof self) !== [])
			{
				throw new \InvalidArgumentException('A compound timeline expression needs operands');
			}
		}
		elseif (!SafeTimelineDiscriminator::supports($field))
		{
			throw new \InvalidArgumentException('Unsafe timeline discriminator field');
		}
	}

	public static function eq(string $field, mixed $value): self
	{
		return new self(self::EQ, $field, $value);
	}

	public static function in(string $field, array $values): self
	{
		if ($values === [])
		{
			throw new \InvalidArgumentException('A timeline IN expression cannot be empty');
		}

		return new self(self::IN, $field, array_values($values));
	}

	public static function isNull(string $field): self
	{
		return new self(self::IS_NULL, $field);
	}

	public static function all(self ...$operands): self
	{
		return new self(self::ALL, operands: $operands);
	}

	public static function any(self ...$operands): self
	{
		return new self(self::ANY, operands: $operands);
	}

	public function getOperator(): string
	{
		return $this->operator;
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getOperands(): array
	{
		return $this->operands;
	}

	public function signature(): array
	{
		return [
			'operator' => $this->operator,
			'field' => $this->field,
			'value' => $this->value,
			'operands' => array_map(static fn(self $item): array => $item->signature(), $this->operands),
		];
	}
}
