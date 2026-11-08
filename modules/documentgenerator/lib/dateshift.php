<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type;

/**
 * Represents a date shift used by the document date modifier.
 *
 * @internal
 */
final class DateShift
{
	public readonly int $amount;
	public readonly DateShiftUnit $unit;

	private function __construct(int $amount, DateShiftUnit $unit)
	{
		$this->amount = $amount;
		$this->unit = $unit;
	}

	public static function tryParse(string $expression): ?self
	{
		if (preg_match('/^([+-])([0-9]{1,6})[ \t]+([a-z]+)\z/i', trim($expression), $matches) !== 1)
		{
			return null;
		}

		$unit = DateShiftUnit::tryFromName($matches[3]);
		if ($unit === null)
		{
			return null;
		}

		$amount = (int)$matches[2];
		if ($matches[1] === '-')
		{
			$amount = -$amount;
		}

		return new self($amount, $unit);
	}

	public function isApplicableTo(Type\Date $value): bool
	{
		return $value::class !== Type\Date::class || !$this->unit->isTimeOfDay();
	}

	public function applyTo(Type\Date $value, string $timezone): Type\Date
	{
		if (!$this->isApplicableTo($value))
		{
			throw new ArgumentException('Time unit is not applicable to date.');
		}

		$shifted = clone $value;
		if ($shifted instanceof Type\DateTime)
		{
			$shifted->setTimeZone(new \DateTimeZone($timezone));
		}

		$designator = ($this->amount < 0 ? '-' : '') . 'P';
		if ($this->unit->isTimeOfDay())
		{
			$designator .= 'T';
		}
		$designator .= abs($this->amount) . $this->unit->getDesignator();
		$shifted->add($designator);

		return $shifted;
	}
}
