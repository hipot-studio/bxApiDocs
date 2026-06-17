<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params;

use Bitrix\Main\Provider\Params\SelectInterface;

abstract class AbstractSelect implements SelectInterface
{
	public function __construct(private readonly array $select)
	{
	}

	public function prepareSelect(): array
	{
		$enumClass = static::fieldsEnumClass();
		$allowedFields = array_flip(array_map(
			static fn($field) => $field->value,
			$enumClass::allowedForSelectList(),
		));

		$prepared = [];
		foreach ($this->select as $field)
		{
			$preparedField = $enumClass::tryFrom($field);
			if ($preparedField === null || !isset($allowedFields[$preparedField->value]))
			{
				continue;
			}

			$prepared[] = $preparedField->toOrmField();
		}

		return $prepared === [] ? $this->getDefaultSelect() : array_values(array_unique($prepared));
	}

	private function getDefaultSelect(): array
	{
		$enumClass = static::fieldsEnumClass();

		return array_map(
			static fn($field) => $field->toOrmField(),
			$enumClass::allowedForSelectList(),
		);
	}

	/**
	 * @return class-string
	 */
	abstract protected static function fieldsEnumClass(): string;
}
