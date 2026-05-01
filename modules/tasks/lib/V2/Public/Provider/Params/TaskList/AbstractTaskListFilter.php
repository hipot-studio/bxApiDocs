<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\TaskList;

use Bitrix\Main\Provider\Params\FilterInterface;

abstract class AbstractTaskListFilter implements FilterInterface
{
	public function getAllowedFields(): array
	{
		return array_map(
			fn (FieldsEnum $field) => $field->value,
			FieldsEnum::allowedForFilterList(),
		);
	}
}
