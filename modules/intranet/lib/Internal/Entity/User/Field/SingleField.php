<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\User\Field;

use Bitrix\Main\Type\Contract\Arrayable;

abstract class SingleField implements Field
{
	public static function createByData(array $fieldData, mixed $value): static
	{
		return new static(
			id: $fieldData['name'],
			title: $fieldData['title'],
			isEditable: $fieldData['editable'] ?? false,
			isShowAlways: $fieldData['showAlways'] ?? false,
			isVisible: $fieldData['isVisible'] ?? false,
			value: static::parseValue($value),
		);
	}

	protected static function parseSingleValue(mixed $value): mixed
	{
		return $value;
	}

	final protected static function parseValue(mixed $value): mixed
	{
		if (is_array($value))
		{
			return array_map(fn (mixed $singleValue) => static::parseSingleValue($singleValue), $value);
		}

		if ($value instanceof Arrayable)
		{
			return array_map(fn (mixed $singleValue) => static::parseSingleValue($singleValue), $value->toArray());
		}

		return static::parseSingleValue($value);
	}

	public function toArray(): array
	{
		return [
			'type' => $this->getType(),
			'isMultiple' => $this->isMultiple(),
			...get_object_vars($this),
		];
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function isEditable(): bool
	{
		return $this->isEditable;
	}

	public function isShowAlways(): bool
	{
		return $this->isShowAlways;
	}

	public function isVisible(): bool
	{
		return $this->isVisible;
	}

	public function isMultiple(): bool
	{
		return false;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getType(): string
	{
		$class = static::class;
		$parts = explode('\\', $class);
		$className = end($parts);
		$className = str_replace('Field', '', $className);

		return strtolower($className);
	}
}
