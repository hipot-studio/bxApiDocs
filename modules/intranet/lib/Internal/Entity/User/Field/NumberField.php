<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

class NumberField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly mixed $value = null,
	)
	{
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		return is_numeric($value) ? (float)$value : $value;
	}

	public function isValid(mixed $value): bool
	{
		return is_float($value);
	}
}
