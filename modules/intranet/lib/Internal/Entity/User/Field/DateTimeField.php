<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class DateTimeField extends DateField
{
	protected static function parseSingleValue(mixed $value): mixed
	{
		if (is_string($value) && !empty($value))
		{
			try
			{
				$value = new DateTime($value);
			}
			catch (ObjectException)
			{
			}
		}

		return $value;
	}

	public function isValid(mixed $value): bool
	{
		return $value instanceof DateTime;
	}
}
