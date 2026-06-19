<?php

namespace Bitrix\StaffTrack\Internals\Attribute;

use Attribute;

#[Attribute]
class Range implements CheckInterface
{
	public function __construct(
		public float $min,
		public float $max,
	)
	{
	}

	public function check(mixed $value): bool
	{
		$floatValue = (float)$value;

		return $floatValue >= $this->min && $floatValue <= $this->max;
	}
}
