<?php

namespace Bitrix\StaffTrack\Internals\Attribute;

use Attribute;

#[Attribute]
class MaxLength implements CheckInterface
{
	public function __construct(public int $maxLength)
	{
	}

	public function check(mixed $value): bool
	{
		if ($value === null)
		{
			return true;
		}

		return mb_strlen((string)$value) <= $this->maxLength;
	}
}
