<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider\Params;

use Bitrix\Main\Provider\Params\Sort;

abstract class AbstractSort extends Sort
{
	public function prepareSort(): array
	{
		$preparedSort = parent::prepareSort();
		$allowedDirection = array_flip($this->getAllowedDirections());
		$enumClass = static::fieldsEnumClass();

		$result = [];
		foreach ($preparedSort as $field => $direction)
		{
			$direction = strtolower((string)$direction);
			if (!isset($allowedDirection[$direction]))
			{
				continue;
			}

			$mappedField = $enumClass::tryFrom((string)$field)?->toOrmField();
			if ($mappedField === null)
			{
				continue;
			}

			$result[$mappedField] = strtoupper($direction);
		}

		return $result;
	}

	public function getAllowedFields(): array
	{
		$enumClass = static::fieldsEnumClass();

		return array_map(
			static fn($field) => $field->value,
			$enumClass::allowedForSortList(),
		);
	}

	public function getAllowedDirections(): array
	{
		return [SortDirection::Asc->value, SortDirection::Desc->value];
	}

	/**
	 * @return class-string
	 */
	abstract protected static function fieldsEnumClass(): string;
}
