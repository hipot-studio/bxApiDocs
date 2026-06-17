<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\StorageField\Converter;

use Bitrix\Bizproc\FieldType;

class BoolConverter implements TypeConverterInterface
{

	public static function getType(): string
	{
		return FieldType::BOOL;
	}

	public function toStorage(mixed $value): ?string
	{
		if ($value === null || $value === '')
		{
			return $value;
		}

		return \CBPHelper::getBool($value) ? '1' : '0';
	}

	public function fromStorage(mixed $value): ?string
	{
		if ($value === null)
		{
			return null;
		}

		return $value ? 'Y' : 'N';
	}
}
