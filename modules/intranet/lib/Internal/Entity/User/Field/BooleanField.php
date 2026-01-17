<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

class BooleanField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly mixed $value = false,
	)
	{
	}

	protected static function parseSingleValue(mixed $value): bool
	{
		return (bool)$value;
	}

	public function isValid(mixed $value): bool
	{
		return is_bool($value);
	}
}
