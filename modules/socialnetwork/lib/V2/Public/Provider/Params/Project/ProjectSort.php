<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Project;

use Bitrix\Main\Provider\Params\Sort;

class ProjectSort extends Sort
{
	public function prepareSort(): array
	{
		$preparedSort = parent::prepareSort();

		$allowedDirection = array_flip($this->getAllowedDirections());
		$result = [];

		foreach ($preparedSort as $field => $direction)
		{
			$direction = strtolower((string)$direction);
			if (!isset($allowedDirection[$direction]))
			{
				continue;
			}

			$mappedField = FieldsEnum::tryFrom((string)$field)?->toOrmField();
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
		return array_map(
			static fn(FieldsEnum $field) => $field->value,
			FieldsEnum::allowedForSortList(),
		);
	}

	public function getAllowedDirections(): array
	{
		return ['asc', 'desc'];
	}
}
