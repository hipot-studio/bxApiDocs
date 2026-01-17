<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Main\PhoneNumber\Parser;

class PhoneField extends StringField
{
	public function isValid(mixed $value): bool
	{
		if (!is_string($value))
		{
			return false;
		}

		if (empty($value))
		{
			return true;
		}

		$phoneNumber = Parser::getInstance()->parse($value);

		return $phoneNumber->isValid();
	}
}
