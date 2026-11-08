<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Balance;

use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceValidator;

final readonly class BalanceValue
{
	public function __construct(private string $value)
	{
		if (!(new BalanceValidator())->isValid($value))
		{
			throw new \InvalidArgumentException('Balance must be a decimal string');
		}
	}

	public function toString(): string
	{
		return $this->value;
	}

	public function isGreaterThan(self $other): bool
	{
		return $this->compare($other) > 0;
	}

	private function compare(self $other): int
	{
		[$negative, $integer, $fraction] = $this->normalizedParts();
		[$otherNegative, $otherInteger, $otherFraction] = $other->normalizedParts();

		if ($this->isZero($integer, $fraction) && $this->isZero($otherInteger, $otherFraction))
		{
			return 0;
		}

		if ($negative !== $otherNegative)
		{
			return $negative ? -1 : 1;
		}

		$absoluteComparison = $this->compareAbsolute(
			$integer,
			$fraction,
			$otherInteger,
			$otherFraction,
		);

		return $negative ? -$absoluteComparison : $absoluteComparison;
	}

	private function normalizedParts(): array
	{
		$value = $this->value;
		$negative = str_starts_with($value, '-');
		if ($negative || str_starts_with($value, '+'))
		{
			$value = substr($value, 1);
		}

		[$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
		$integer = ltrim($integer, '0');

		return [$negative, $integer === '' ? '0' : $integer, rtrim($fraction, '0')];
	}

	private function isZero(string $integer, string $fraction): bool
	{
		return $integer === '0' && $fraction === '';
	}

	private function compareAbsolute(
		string $integer,
		string $fraction,
		string $otherInteger,
		string $otherFraction,
	): int
	{
		$lengthComparison = strlen($integer) <=> strlen($otherInteger);
		if ($lengthComparison !== 0)
		{
			return $lengthComparison;
		}

		$integerComparison = strcmp($integer, $otherInteger);
		if ($integerComparison !== 0)
		{
			return $integerComparison <=> 0;
		}

		$length = max(strlen($fraction), strlen($otherFraction));
		$fractionComparison = strcmp(
			str_pad($fraction, $length, '0'),
			str_pad($otherFraction, $length, '0'),
		);

		return $fractionComparison <=> 0;
	}
}
