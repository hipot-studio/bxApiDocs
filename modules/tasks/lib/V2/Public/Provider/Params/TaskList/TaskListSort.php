<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\Provider\Params\Sort;

class TaskListSort extends Sort
{
	public function prepareSort(): array
	{
		$preparedSort = parent::prepareSort();

		$result = [];
		$allowedDirection = array_flip($this->getAllowedDirections());

		foreach ($preparedSort as $field => $direction)
		{
			if (isset($allowedDirection[$direction]))
			{
				$result[$field] = $direction;
			}
		}

		return $result;
	}

	public function getAllowedFields(): array
	{
		return array_map(
			static fn (FieldsEnum $field) => $field->value,
			FieldsEnum::allowedForSortList(),
		);
	}

	public function getAllowedDirections(): array
	{
		return [
			'asc',
			'desc',
		];
	}
}
