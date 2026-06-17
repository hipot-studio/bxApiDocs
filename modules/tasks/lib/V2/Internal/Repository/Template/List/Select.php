<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

class Select
{
	protected array $list = [];

	public function __construct(array $list = [])
	{
		foreach ($list as $field)
		{
			if (!$field instanceof Field)
			{
				continue;
			}

			$this->list[$field->value] = $field;
		}
	}

	public function getList(): array
	{
		return array_values($this->list);
	}

	public function has(Field $field): bool
	{
		return isset($this->list[$field->value]);
	}
}