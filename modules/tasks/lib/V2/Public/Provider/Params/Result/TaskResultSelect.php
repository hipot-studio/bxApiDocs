<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Params\Result;

use Bitrix\Main\Provider\Params\SelectInterface;
use Bitrix\Tasks\V2\Public\Provider\Map\Result\TaskResultFieldToColumnMap;

class TaskResultSelect implements SelectInterface
{
	public function __construct(
		private readonly array $conditions = [],
	)
	{
	}

	public function prepareSelect(): array
	{
		$result = [];

		$selectStatement = array_unique($this->conditions);
		foreach ($selectStatement as $field)
		{
			$column = TaskResultFieldToColumnMap::RELATIONS[$field] ?? null;
			if ($column === null)
			{
				continue;
			}

			if (is_array($column))
			{
				$key = array_key_first($column);

				$result[$key] = $column[$key];

				continue;
			}

			$result[] = $column;
		}

		return $result;
	}

	public function isInConditions(string $name): bool
	{
		return in_array($name, $this->conditions, true);
	}
}
