<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\Provider\Params\SelectInterface;

class TaskListSelect implements SelectInterface
{
	public function __construct(private readonly array $select)
	{
	}

	public function prepareSelect(): array
	{
		$allowedFields = array_flip(array_map(
			static fn (FieldsEnum $field) => $field->value,
			FieldsEnum::allowedForSelectList(),
		));

		$prepared = [];
		foreach ($this->select as $field)
		{
			$preparedField = FieldsEnum::tryFrom($field);
			if ($preparedField === null
				|| !isset($allowedFields[$preparedField->value])
			)
			{
				continue;
			}

			$prepared[$preparedField->value] = $preparedField->value;
		}

		return $prepared;
	}
}
