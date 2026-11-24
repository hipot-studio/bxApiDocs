<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

final readonly class Coefficient
{
	public function __construct(private int $total, private int $max)
	{
		if ($max <= 0)
		{
			throw new \InvalidArgumentException('Maximum must be positive.');
		}
	}

	public function getTotal(): int
	{
		return $this->total;
	}

	public function getMax(): int
	{
		return $this->max;
	}

	public function isSuper(): bool
	{
		return $this->total > $this->max;
	}

	public function getValue(): float
	{
		return $this->total / $this->max;
	}

	public function format(): string
	{
		$value = rtrim(rtrim(number_format($this->getValue(), 2, '.', ''), '0'), '.');

		return $value === '-0' ? '0' : $value;
	}
}
