<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Main\ArgumentException;

class SelectField extends SingleField
{
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly bool $isEditable,
		public readonly bool $isShowAlways,
		public readonly bool $isVisible,
		/** @var $items array<string, string> */
		public readonly array $items,
		public readonly mixed $value = null,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 */
	public static function createByData(array $fieldData, mixed $value): static
	{
		$items = [];

		if (
			$fieldData['type'] === 'enumeration'
			&& isset($fieldData['data']['fieldInfo']['ENUM'])
			&& is_array($fieldData['data']['fieldInfo']['ENUM'])
		)
		{
			foreach ($fieldData['data']['fieldInfo']['ENUM'] as $enum)
			{
				if (isset($enum['ID'], $enum['VALUE']))
				{
					$items[(int)$enum['ID']] = $enum['VALUE'];
				}
			}
		}
		elseif (!empty($fieldData['data']['items']))
		{
			foreach ($fieldData['data']['items'] as $item)
			{
				$items[$item['VALUE']] = $item['NAME'];
			}
		}
		else
		{
			throw new ArgumentException('Selectable user field required items');
		}

		return new static(
			id: $fieldData['name'],
			title: $fieldData['title'],
			isEditable: $fieldData['editable'] ?? false,
			isShowAlways: $fieldData['showAlways'] ?? false,
			isVisible: $fieldData['isVisible'] ?? false,
			items: $items,
			value: static::parseValue($value),
		);
	}

	public function isValid(mixed $value): bool
	{
		return (is_int($value) || is_string($value))
			&& array_key_exists($value, $this->items);
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			'items' => $this->items,
		];
	}
}
