<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\Date;

class DateField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		public readonly mixed $value = null,
		public readonly string $format = '',
	)
	{
	}

	public static function createByData(array $fieldData, mixed $value): static
	{
		$format = isset($fieldData['data']['dateViewFormat']) && is_string($fieldData['data']['dateViewFormat'])
			? $fieldData['data']['dateViewFormat']
			: '';

		return new static(
			id: $fieldData['name'],
			title: $fieldData['title'],
			isEditable: $fieldData['editable'] ?? false,
			isShowAlways: $fieldData['showAlways'] ?? false,
			isVisible: $fieldData['isVisible'] ?? false,
			value: static::parseValue($value),
			format: $format,
		);
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		if (is_string($value) && !empty($value))
		{
			try
			{
				$value = new Date($value);
			}
			catch (ObjectException)
			{
			}
		}

		return $value;
	}

	public function isValid(mixed $value): bool
	{
		return $value instanceof Date;
	}

	public function isVisible(): bool
	{
		return $this->isVisible;
	}
}
