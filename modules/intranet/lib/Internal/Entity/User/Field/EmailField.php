<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

class EmailField extends StringField
{
	public function isValid(mixed $value): bool
	{
		return is_string($value)
			&& (
				empty($value)
				|| check_email($value)
			);
	}
}
